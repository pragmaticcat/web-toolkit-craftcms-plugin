<?php

namespace pragmatic\webtoolkit\db;

use Illuminate\Database\Query\Expression as BaseExpression;

/**
 * Expressió SQL en brut que accepta la sintaxi de Craft 5 per a columnes (`[[col]]`) i taules (`{{%taula}}`).
 */
class Expression extends BaseExpression
{
    public function __construct(string $value)
    {
        parent::__construct(Sql::normalize($value));
    }
}
