<?php

namespace App\Mdm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What counts as work. kind=domain: a web address that matches itself and its subdomains (e.g. noahport.co.ke
 * matches app.noahport.co.ke). kind=app: an Android package name (e.g. com.safaricom.mpesa.lifestyle).
 */
#[Fillable(['kind', 'pattern', 'label'])]
class WorkRule extends Model
{
    protected $table = 'mdm_work_rules';

    protected static function booted(): void
    {
        static::saving(function (WorkRule $rule) {
            $rule->pattern = strtolower(trim($rule->kind === 'domain'
                ? preg_replace('#^(https?://)?(www\.)?([^/]+).*$#i', '$3', $rule->pattern)
                : $rule->pattern));
        });
    }
}
