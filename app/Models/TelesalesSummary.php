<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelesalesSummary extends Model
{
    protected $fillable = [
        'summary_date',
        'gross_sales',
        'net_income',
        'top_seller_name',
        'top_seller_gross_sales',
        'top_seller_net_income',
        'top_team_name',
        'top_team_gross_sales',
        'top_team_net_income',
        'sub_team_counts',
        'overall_working_tsas',
    ];

    protected $casts = [
        'summary_date'            => 'date',
        'gross_sales'             => 'decimal:2',
        'net_income'              => 'decimal:2',
        'top_seller_gross_sales'  => 'decimal:2',
        'top_seller_net_income'   => 'decimal:2',
        'top_team_gross_sales'    => 'decimal:2',
        'top_team_net_income'     => 'decimal:2',
        'sub_team_counts'         => 'array',
        'overall_working_tsas'    => 'integer',
    ];
}
