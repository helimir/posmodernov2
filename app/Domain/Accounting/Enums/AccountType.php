<?php

namespace App\Domain\Accounting\Enums;

/**
 * El TIPO contable de una cuenta: que clase de movimiento representa.
 *
 * Es un conjunto CERRADO, y el dominio lo usa para validar: el rol que una
 * cuenta cumple en una tienda exige un tipo concreto, y una cuenta de ingresos
 * no puede ser la caja de nadie.
 *
 * Los valores son los del enum de Postgres `account_type`, y no se anade
 * ninguno mas sin justificarlo.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
