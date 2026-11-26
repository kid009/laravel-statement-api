<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeStatementRequest;
use App\Models\StatementAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use RuntimeException;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Pdf;

#[OA\Info(
    version: '1.0.0',
    title: 'Bank Statement Analyzer API',
    description: 'API สำหรับอ่านและวิเคราะห์ไฟล์ PDF Bank Statement ภาษาอังกฤษ พร้อมตรวจสอบเงินหมุนเวียนและความถูกต้องของเอกสาร'
)]
class StatementController extends Controller
{
    #[OA\Post(
        path: '/api/v1/statements/analyze',
        summary: 'วิเคราะห์ไฟล์ PDF Bank Statement (ภาษาอังกฤษ)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['bank_code', 'file'],
                    properties: [
                        new OA\Property(
                            property: 'bank_code',
                            type: 'string',
                            enum: ['ttb', 'kbank', 'scb', 'bbl', 'ktb', 'bay'],
                            example: 'ttb',
                            description: 'เลือกธนาคารของไฟล์ Statement'
                        ),
                        new OA\Property(
                            property: 'file',
                            type: 'string',
                            format: 'binary',
                            description: 'ไฟล์ e-Statement ภาษาอังกฤษ (PDF เท่านั้น ขนาดไม่เกิน 10MB)'
                        ),
                        new OA\Property(
                            property: 'password',
                            type: 'string',
                            nullable: true,
                            example: '11111985',
                            description: 'รหัสผ่านเปิดไฟล์ PDF (ถ้ามี ระบบจะไม่เก็บรหัสผ่านนี้ไว้)'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'วิเคราะห์สำเร็จและบันทึกข้อมูลแล้ว'),
            new OA\Response(response: 422, description: 'อายุไม่ถึง 3 เดือน, เลือกธนาคารไม่ตรงกับไฟล์ หรือไม่สามารถอ่านไฟล์ PDF ได้'),
        ]
    )]
    public function analyze(AnalyzeStatementRequest $request): JsonResponse
    {
        $bankCode = $request->validated('bank_code');
        $filePath = $request->file('file')->getRealPath();
        $password = $request->validated('password');

        // 1. ดึงข้อความจาก PDF
        try {
            $rawText = $this->extractTextFromPdf($filePath, $password);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getCode() === 401 ? 'PDF_PASSWORD_ERROR' : 'PDF_EXTRACT_ERROR',
            ], 422);
        }

        // 2. แยกเส้นทางอ่านตามธนาคารที่เลือกจาก DDL
        try {
            $parsed = match ($bankCode) {
                'ttb' => $this->parseTtbStatement($rawText),
                default => throw new RuntimeException('ระบบยังไม่รองรับการอ่าน Statement ของธนาคาร ' . strtoupper($bankCode), 422),
            };
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'BANK_PARSER_ERROR',
            ], 422);
        }

        if (empty($parsed['account_holder_name'])) {
            return response()->json([
                'message' => 'ไม่พบชื่อเจ้าของบัญชีในเอกสาร กรุณาตรวจสอบว่าเป็นไฟล์ e-Statement ภาษาอังกฤษ',
                'code' => 'ACCOUNT_NAME_NOT_FOUND',
            ], 422);
        }

        if ($parsed['latest_balance'] === null) {
            return response()->json([
                'message' => 'ไม่พบรายการเดินบัญชีหรือยอดเงินคงเหลือในเอกสาร',
                'code' => 'TRANSACTIONS_NOT_FOUND',
            ], 422);
        }

        // 3. ตรวจสอบเงื่อนไขเงินเข้าประจำครบ 3 เดือน
        $validationResult = $this->validateThreeMonthSalary($parsed['credit_transactions']);

        if (! $validationResult['is_valid']) {
            return response()->json([
                'message' => 'อายุไม่ถึง 3 เดือน',
                'code' => 'STATEMENT_PERIOD_TOO_SHORT',
                'income_dates' => array_column($validationResult['matched_transactions'], 'date'),
                'matched_transactions' => $validationResult['matched_transactions'],
            ], 422);
        }

        // 4. ตรวจสอบร่องรอยการแก้ไข PDF และคำนวณเงินหมุนเวียน
        $metadataWarnings = $this->inspectPdfMetadata($filePath);
        $allWarnings = array_values(array_unique(array_merge($parsed['audit_warnings'], $metadataWarnings)));
        $isReconciled = count($allWarnings) === 0;

        $totalTurnover = bcadd($parsed['total_deposits'], $parsed['total_withdrawals'], 2);

        // 5. บันทึกผลลัพธ์ลง MySQL
        $analysis = StatementAnalysis::create([
            'bank_code' => $bankCode,
            'account_holder_name' => $parsed['account_holder_name'],
            'latest_balance' => $parsed['latest_balance'],
            'total_deposits' => $parsed['total_deposits'],
            'total_withdrawals' => $parsed['total_withdrawals'],
            'total_turnover' => $totalTurnover,
            'recurring_income_amount' => $validationResult['recurring_amount'],
            'matched_transactions' => $validationResult['matched_transactions'],
            'is_reconciled' => $isReconciled,
            'audit_warnings' => $allWarnings,
            'statement_start_date' => $parsed['start_date'],
            'statement_end_date' => $parsed['end_date'],
        ]);

        return response()->json([
            'message' => 'วิเคราะห์ Statement สำเร็จ',
            'data' => [
                'id' => $analysis->id,
                'bank_code' => $analysis->bank_code,
                'account_holder_name' => $analysis->account_holder_name,
                'recurring_income_amount' => $analysis->recurring_income_amount,
                'latest_balance' => $analysis->latest_balance,
                'cash_flow' => [
                    'total_deposits' => $analysis->total_deposits,
                    'total_withdrawals' => $analysis->total_withdrawals,
                    'total_turnover' => $analysis->total_turnover,
                ],
                'integrity_check' => [
                    'is_genuine_math' => $analysis->is_reconciled,
                    'warnings' => $analysis->audit_warnings,
                ],
                'matched_transactions' => $analysis->matched_transactions,
                'statement_start_date' => $analysis->statement_start_date?->format('Y-m-d'),
                'statement_end_date' => $analysis->statement_end_date?->format('Y-m-d'),
            ],
        ], 200);
    }

    private function extractTextFromPdf(string $filePath, ?string $password): string
    {
        $binaryPath = config('services.pdftotext.path', 'pdftotext');

        $options = [
            '-layout',
            '-enc UTF-8',
        ];

        if (! empty($password)) {
            $options[] = '-upw ' . escapeshellarg($password);
        }

        try {
            $text = (new Pdf($binaryPath))
                ->setPdf($filePath)
                ->setOptions($options)
                ->text();
        } catch (CouldNotExtractText $e) {
            $error = strtolower($e->getMessage());

            if (str_contains($error, 'password')) {
                $msg = empty($password)
                    ? 'ไฟล์นี้ติดรหัสผ่าน กรุณาระบุรหัสผ่านเปิดไฟล์'
                    : 'รหัสผ่านเปิดไฟล์ PDF ไม่ถูกต้อง';

                throw new RuntimeException($msg, 401);
            }

            throw new RuntimeException('ไม่สามารถอ่านไฟล์ PDF ได้: ' . $e->getMessage(), 422);
        }

        if (trim($text) === '') {
            throw new RuntimeException('ไม่พบข้อความในไฟล์ (ไม่รองรับไฟล์สแกนหรือรูปภาพ)', 422);
        }

        return $text;
    }

    /**
     * ตรวจ Metadata ของ PDF ด้วย pdfinfo.exe เพื่อหาโปรแกรมแก้ไข/ปลอมแปลงเอกสาร
     */
    private function inspectPdfMetadata(string $filePath): array
    {
        $pdftotextPath = config('services.pdftotext.path', 'pdftotext');
        $pdfinfoPath = str_replace('pdftotext', 'pdfinfo', $pdftotextPath);

        if (! file_exists($pdfinfoPath)) {
            return [];
        }

        $output = shell_exec(escapeshellarg($pdfinfoPath) . ' ' . escapeshellarg($filePath) . ' 2>&1');
        if (! is_string($output)) {
            return [];
        }

        $warnings = [];
        $suspiciousTools = [
            'photoshop',
            'illustrator',
            'canva',
            'ilovepdf',
            'smallpdf',
            'foxit',
            'nitro',
            'adobe acrobat pro',
            'sejda',
            'wps',
            'microsoft word',
        ];

        if (preg_match('/^(?:Creator|Producer):\s*(.+)$/mi', $output, $matches)) {
            $producerInfo = strtolower(trim($matches[1]));
            foreach ($suspiciousTools as $tool) {
                if (str_contains($producerInfo, $tool)) {
                    $warnings[] = 'ตรวจพบไฟล์ถูกสร้างหรือแก้ไขผ่านโปรแกรมภายนอก (' . trim($matches[1]) . ')';
                    break;
                }
            }
        }

        return $warnings;
    }

    /**
     * Parser เฉพาะธนาคารทหารไทยธนชาต (ttb) พร้อมตรวจกระทบยอดเงินหมุนเวียน
     */
    private function parseTtbStatement(string $rawText): array
    {
        if (! str_contains($rawText, 'TMBThanachart') && ! str_contains($rawText, 'ttbbank.com')) {
            throw new RuntimeException('ไฟล์เอกสารไม่ตรงกับธนาคาร ttb ที่เลือก กรุณาตรวจสอบธนาคารอีกครั้ง', 422);
        }

        // 1. จับชื่อเจ้าของบัญชี
        $accountName = null;
        if (preg_match('/Customer\s+name[^\r\n]*\R\s*([A-Z][A-Z\s\.\-]{1,60}?)(?=\s{2,}|Account\s+no|\r|\n)/m', $rawText, $nameMatch)) {
            $accountName = trim(preg_replace('/\s+/', ' ', $nameMatch[1]));
        } elseif (preg_match('/Customer\s+name\s+([A-Z][A-Z\s\.\-]{1,60}?)(?=\s{2,}|Account\s+name|\r|\n)/m', $rawText, $nameMatch)) {
            $accountName = trim(preg_replace('/\s+/', ' ', $nameMatch[1]));
        }

        $monthsPattern = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
        $yearPattern = '(?:2[4-9]|3[0-5])';

        // 2. ดึงช่วงวันที่จากหัวเอกสาร
        $periodStartDate = null;
        $periodEndDate = null;
        if (preg_match("/Period\s+(\d{1,2}\s+{$monthsPattern}\s+{$yearPattern})\s*-\s*(\d{1,2}\s+{$monthsPattern}\s+{$yearPattern})/", $rawText, $periodMatch)) {
            $periodStartDate = CarbonImmutable::createFromFormat('d M y', trim($periodMatch[1]))->format('Y-m-d');
            $periodEndDate = CarbonImmutable::createFromFormat('d M y', trim($periodMatch[2]))->format('Y-m-d');
        }

        // 3. ดึงยอดสรุปเงินเข้า-เงินออกที่ระบุไว้บนหัวกระดาษหน้า 1
        $declaredDeposits = null;
        $declaredWithdrawals = null;
        if (preg_match('/Total\s+deposits\s+\d+\s+Txns\s+([\d,]+\.\d{2})/i', $rawText, $depMatch)) {
            $declaredDeposits = str_replace(',', '', $depMatch[1]);
        }
        if (preg_match('/Total\s+withdrawals\s+\d+\s+Txns\s+([\d,]+\.\d{2})/i', $rawText, $wdMatch)) {
            $declaredWithdrawals = str_replace(',', '', $wdMatch[1]);
        }

        // 4. รวมวันที่ที่ถูกตัดแยกบรรทัด
        $normalizedText = preg_replace("/\b(\d{1,2})\s*\R\s*({$monthsPattern})\s*\R\s*(?<!\.)({$yearPattern})\b/", '$1 $2 $3', $rawText);
        $normalizedText = preg_replace("/\b(\d{1,2}\s+{$monthsPattern})\s*\R\s*(?<!\.)({$yearPattern})\b/", '$1 $2', $normalizedText);
        $normalizedText = preg_replace("/(?<!\.)\b({$yearPattern})\s*\R\s*(\d{1,2}\s+{$monthsPattern})\b/", '$2 $1', $normalizedText);
        $normalizedText = preg_replace("/\b({$monthsPattern})\s+(?<!\.)({$yearPattern})\s*\R\s*(\d{1,2})\b/", '$3 $1 $2', $normalizedText);

        $txSection = preg_replace("/Period\s+\d{1,2}\s+{$monthsPattern}\s+{$yearPattern}\s*-\s*\d{1,2}\s+{$monthsPattern}\s+{$yearPattern}/", '', $normalizedText);

        // 5. อ่านรายการธุรกรรมทั้งหมดเพื่อคำนวณเงินหมุนเวียนจริง
        $tokenPattern = "/(?P<date>\b\d{1,2}\s+{$monthsPattern}\s+(?<!\.){$yearPattern}\b)|(?P<amount>[+-][\d,]+\.\d{2})\s+(?P<balance>[\d,]+\.\d{2})/";
        preg_match_all($tokenPattern, $txSection, $matches, PREG_SET_ORDER);

        $creditTransactions = [];
        $latestBalance = null;
        $oldestTx = null;
        $currentDate = null;
        $allDates = [];

        $calculatedDeposits = '0.00';
        $calculatedWithdrawals = '0.00';

        foreach ($matches as $m) {
            if (! empty($m['date'])) {
                $parsedDate = CarbonImmutable::createFromFormat('d M y', trim($m['date']));
                if ($parsedDate !== false) {
                    $currentDate = $parsedDate->format('Y-m-d');
                    $allDates[] = $currentDate;
                }
                continue;
            }

            if (! empty($m['amount']) && $currentDate !== null) {
                $rawAmount = str_replace(',', '', $m['amount']);
                $balance = str_replace(',', '', $m['balance']);
                $unsignedAmount = ltrim($rawAmount, '+-');

                if ($latestBalance === null) {
                    $latestBalance = $balance;
                }

                // จำรายการเก่าสุด (บรรทัดสุดท้ายของเอกสาร) ไว้คำนวณยอดยกมาตั้งต้น
                $oldestTx = [
                    'signed_amount' => $rawAmount,
                    'balance' => $balance,
                ];

                if (str_starts_with($rawAmount, '+')) {
                    $calculatedDeposits = bcadd($calculatedDeposits, $unsignedAmount, 2);
                    $creditTransactions[] = [
                        'date' => $currentDate,
                        'amount' => $unsignedAmount,
                        'description' => 'Transfer in',
                    ];
                } else {
                    $calculatedWithdrawals = bcadd($calculatedWithdrawals, $unsignedAmount, 2);
                }
            }
        }

        // 6. ตรวจสอบความถูกต้องของตัวเลข (Anti-Tampering Audit)
        $auditWarnings = [];

        if ($declaredDeposits !== null && bccomp($declaredDeposits, $calculatedDeposits, 2) !== 0) {
            $auditWarnings[] = "ยอดเงินเข้าไม่ตรงกับหัวเอกสาร (หัวเอกสาร: {$declaredDeposits}, คำนวณจริง: {$calculatedDeposits})";
        }

        if ($declaredWithdrawals !== null && bccomp($declaredWithdrawals, $calculatedWithdrawals, 2) !== 0) {
            $auditWarnings[] = "ยอดเงินออกไม่ตรงกับหัวเอกสาร (หัวเอกสาร: {$declaredWithdrawals}, คำนวณจริง: {$calculatedWithdrawals})";
        }

        // ตรวจสมการบัญชี: ยอดยกมาตั้งต้น + เงินเข้าทั้งหมด - เงินออกทั้งหมด == ยอดคงเหลือล่าสุด
        if ($oldestTx !== null && $latestBalance !== null) {
            // ยอดยกมาก่อนรายการเก่าสุด = balance_เก่าสุด - signed_amount_เก่าสุด
            $openingBalance = bcsub($oldestTx['balance'], $oldestTx['signed_amount'], 2);
            $expectedLatestBalance = bcsub(bcadd($openingBalance, $calculatedDeposits, 2), $calculatedWithdrawals, 2);

            if (bccomp($expectedLatestBalance, $latestBalance, 2) !== 0) {
                $auditWarnings[] = "ยอดคงเหลือไม่สอดคล้องกับรายการเดินบัญชี (คำนวณได้: {$expectedLatestBalance}, ในเอกสาร: {$latestBalance})";
            }
        }

        sort($allDates);

        return [
            'account_holder_name' => $accountName,
            'latest_balance' => $latestBalance,
            'total_deposits' => $calculatedDeposits,
            'total_withdrawals' => $calculatedWithdrawals,
            'credit_transactions' => $creditTransactions,
            'audit_warnings' => $auditWarnings,
            'start_date' => $periodStartDate ?? ($allDates[0] ?? null),
            'end_date' => $periodEndDate ?? (! empty($allDates) ? end($allDates) : null),
        ];
    }

    private function validateThreeMonthSalary(array $creditTransactions, string $minBaseAmount = '5000.00'): array
    {
        $candidates = array_values(array_filter(
            $creditTransactions,
            fn (array $tx) => bccomp($tx['amount'], $minBaseAmount, 2) >= 0
        ));

        usort($candidates, fn ($a, $b) => strcmp($a['date'], $b['date']));

        if (count($candidates) < 3) {
            return [
                'is_valid' => false,
                'recurring_amount' => null,
                'matched_transactions' => $candidates,
            ];
        }

        $bestChain = [];

        foreach ($candidates as $startIndex => $startTx) {
            $chain = [$startTx];
            $baseAmount = (float) $startTx['amount'];

            for ($i = $startIndex + 1; $i < count($candidates); $i++) {
                $tx = $candidates[$i];
                $lastTx = end($chain);

                $lastDate = CarbonImmutable::parse($lastTx['date']);
                $currentDate = CarbonImmutable::parse($tx['date']);

                $daysDiff = $lastDate->diffInDays($currentDate);
                $isDifferentMonth = $lastDate->format('Y-m') !== $currentDate->format('Y-m');

                $currentAmount = (float) $tx['amount'];
                $amountDiffRatio = abs($currentAmount - $baseAmount) / $baseAmount;

                if ($isDifferentMonth && $daysDiff >= 22 && $daysDiff <= 38 && $amountDiffRatio <= 0.05) {
                    $chain[] = $tx;
                }
            }

            if (count($chain) > count($bestChain)) {
                $bestChain = $chain;
            }
        }

        if (count($bestChain) < 3) {
            return [
                'is_valid' => false,
                'recurring_amount' => null,
                'matched_transactions' => $candidates,
            ];
        }

        $latestThree = array_slice($bestChain, -3);
        $latestAmount = end($latestThree)['amount'];

        return [
            'is_valid' => true,
            'recurring_amount' => $latestAmount,
            'matched_transactions' => $latestThree,
        ];
    }
}
