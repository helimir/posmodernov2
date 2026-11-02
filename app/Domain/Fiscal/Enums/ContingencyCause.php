<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Causales tasadas de la contingencia en papel.
 *
 * Son GENERICAS: describen por que se cae el sistema, no de que pais es. El
 * valor vive en el enum, no en una condicion del codigo, y el conjunto es
 * cerrado: una causa que no esta aqui no es una causa.
 */
enum ContingencyCause: string
{
    /** Falla prolongada de la energia electrica. */
    case PowerFailure = 'power_failure';

    /** Interrupcion severa de la conectividad. */
    case ConnectivityFailure = 'connectivity_failure';

    /** Inoperancia o averia tecnica comprobada del medio de emision. */
    case EquipmentFailure = 'equipment_failure';

    /** Mantenimiento critico no programado del sistema digital. */
    case Maintenance = 'maintenance';

    /** Imposibilidad de emitir notas de debito o credito. */
    case CannotIssueNotes = 'cannot_issue_notes';

    /** Operaciones por cuenta de terceros. */
    case ThirdPartyOperations = 'third_party_operations';

    /** Operaciones de exportacion. */
    case ExportOperations = 'export_operations';

    /** Emision de mas de una copia por exigencia legal o de un organo publico. */
    case MultipleCopiesRequired = 'multiple_copies_required';
}
