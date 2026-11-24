/**
 * The vocabulary of the PRINTING, as the screens see it.
 *
 * The CONTRACT is the same in every screen that talks about printing (the
 * till, the cash day and the print queue): the state of a ticket, the state
 * of a print job and the reasons why a ticket was not queued.
 *
 * The vocabulary is the DOMAIN's, not the interface's: the screen does not
 * invent a state of its own, it translates the one it receives.
 */

/**
 * The STATE of a TICKET: the answer of the checkout.
 *
 *  - `queued`: the ticket was queued. It carries the job id.
 *  - `not_queued`: the ticket was not queued. It carries the reason.
 */
export interface PrintQueueStatus {
    status: 'queued' | 'not_queued';
    job_id: number | null;
    reason: string | null;
    message: string | null;
}

/**
 * The REASONS why a ticket was not queued.
 *
 * The set is CLOSED: the domain declares it, and the screen translates
 * exactly these. A reason the screen does not know is a raw code in the
 * face of the cashier.
 */
export type PrintNotQueuedReason =
    | 'auto_print_disabled'
    | 'no_destination'
    | 'ambiguous_destination'
    | 'no_document'
    | 'document_not_issued'
    | 'dispatch_failed';

/**
 * The LABEL of a state of ticket, in the language of the interface.
 */
export function printLabel(status: string): string {
    switch (status) {
        case 'queued':
            return 'Queued';
        case 'not_queued':
            return 'Not queued';
        default:
            return status;
    }
}

/**
 * The COLOR of a state of ticket: a ticket that did not go out is red.
 */
export function printVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'queued':
            return 'default';
        case 'not_queued':
            return 'destructive';
        default:
            return 'outline';
    }
}

/**
 * The LABEL of a reason, in the language of the interface.
 *
 * The reason says WHY the ticket did not go out, and the cashier needs to
 * know it before the client walks away.
 */
export function printReasonLabel(reason: string | null): string | null {
    switch (reason) {
        case 'auto_print_disabled':
            return 'This store does not print automatically';
        case 'no_destination':
            return 'This store has no active print destination';
        case 'ambiguous_destination':
            return 'This store has more than one print destination';
        case 'no_document':
            return 'The sale has no fiscal document';
        case 'document_not_issued':
            return 'The fiscal document was not issued';
        case 'dispatch_failed':
            return 'The print destination could not compose the ticket';
        default:
            return null;
    }
}

/**
 * The STATE of a PRINT JOB, as the state machine writes it.
 */
export type PrintJobStatus = 'queued' | 'claimed' | 'printing' | 'printed' | 'failed' | 'cancelled';

/**
 * The LABEL of the state of a print job.
 */
export function printJobLabel(status: string): string {
    switch (status) {
        case 'queued':
            return 'Queued';
        case 'claimed':
            return 'Claimed';
        case 'printing':
            return 'Printing';
        case 'printed':
            return 'Printed';
        case 'failed':
            return 'Failed';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

/**
 * The COLOR of the state of a print job: a failure is red, the rest is
 * quiet.
 */
export function printJobVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'printed') {
        return 'default';
    }

    return 'secondary';
}
