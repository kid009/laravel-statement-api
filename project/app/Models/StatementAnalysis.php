<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatementAnalysis extends Model
{
    protected $table = 'statement_analyses';

    protected $fillable = [
        'bank_code',
        'account_holder_name',
        'latest_balance',
        'total_deposits',
        'total_withdrawals',
        'total_turnover',
        'recurring_income_amount',
        'matched_transactions',
        'is_reconciled',
        'audit_warnings',
        'statement_start_date',
        'statement_end_date',
    ];

    protected $casts = [
        'latest_balance' => 'string',
        'total_deposits' => 'string',
        'total_withdrawals' => 'string',
        'total_turnover' => 'string',
        'recurring_income_amount' => 'string',
        'matched_transactions' => 'array',
        'is_reconciled' => 'boolean',
        'audit_warnings' => 'array',
        'statement_start_date' => 'date:Y-m-d',
        'statement_end_date' => 'date:Y-m-d',
    ];
}
