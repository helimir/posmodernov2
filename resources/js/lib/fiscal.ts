/**
 * The FISCAL STATUS of a sale, as the screens see it.
 *
 * The CONTRACT is the same in the three screens (the list, the detail and the
 * till): the status of the fiscal document of the sale, with its control
 * number and, when the issuance failed, the reason.
 *
 *  - `issued`: the document was issued. It carries its control number.
 *  - `failed`: the issuance failed. It carries the reason, and the screen
 *    offers the retry.
 *  - `not_emitted`: nothing was issued (the switch is off, or the sale has
 *    no document yet).
 *
 * The vocabulary is the DOMAIN's, not the interface's: the screen does not
 * invent a status of its own, it translates the one it receives.
 */
export interface FiscalStatus {
    status: 'issued' | 'failed' | 'not_emitted';
    document_id: number | null;
    control_number: string | null;
    message: string | null;
    /** The link to the receipt. Only the screens that offer it carry it. */
    receipt_url?: string | null;
    /** Whether the issuance can be retried. */
    can_retry?: boolean;
}

/**
 * The LABEL of the fiscal status, in the language of the interface.
 */
export function fiscalLabel(status: string): string {
    switch (status) {
        case 'issued':
            return 'Issued';
        case 'failed':
            return 'Failed';
        case 'not_emitted':
            return 'Not issued';
        default:
            return status;
    }
}

/**
 * The COLOR of the fiscal status.
 */
export function fiscalVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'issued':
            return 'default';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}
