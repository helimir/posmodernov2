import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LogOut, Store } from 'lucide-react';

interface PosLayoutProps {
    /** El nombre de la tienda donde se cobra. */
    storeName: string;
    /** La fecha de negocio: el dia que manda la caja. */
    businessDate: string;
    children: React.ReactNode;
}

/**
 * El LAYOUT de la caja: la pantalla COMPLETA, sin navegacion lateral.
 *
 * REGLA que fija este layout: *la caja no es una pagina mas del panel*. Un
 * cajero no quiere una barra lateral con once secciones que no usa: quiere
 * el catalogo, el carrito y el cobro, a pantalla completa y a un toque. Por
 * eso la caja tiene su propio layout en vez del `AppLayout` con sidebar.
 *
 * LO QUE TIENE, Y POR QUE:
 *
 *  - La barra superior: la TIENDA y la FECHA DE NEGOCIO (el dia que la caja
 *    esta cobrando, que no es necesariamente el dia del reloj), el USUARIO que
 *    esta cobrando y la SALIDA.
 *  - El cuerpo: lo que la pantalla de la caja pone dentro.
 */
export default function PosLayout({ storeName, businessDate, children }: PosLayoutProps) {
    const { auth } = usePage<SharedData>().props;

    return (
        <div className="bg-background text-foreground flex h-dvh w-full flex-col overflow-hidden">
            <header className="bg-card flex shrink-0 items-center justify-between gap-4 border-b px-4 py-2">
                <div className="flex items-center gap-3">
                    <Store className="text-muted-foreground size-5" />
                    <span className="text-lg font-semibold">{storeName}</span>
                    <span className="bg-muted text-muted-foreground rounded-md px-2 py-1 text-sm tabular-nums">{businessDate}</span>
                </div>

                <div className="flex items-center gap-3">
                    <span className="text-muted-foreground text-sm">{auth.user?.name}</span>

                    <Link
                        method="post"
                        href={route('logout')}
                        as="button"
                        className="hover:bg-accent hover:text-accent-foreground inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors"
                    >
                        <LogOut className="size-4" />
                        Exit
                    </Link>
                </div>
            </header>

            <main className="flex min-h-0 flex-1 flex-col overflow-hidden">{children}</main>
        </div>
    );
}
