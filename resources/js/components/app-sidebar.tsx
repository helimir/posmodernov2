import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { BookOpen, Folder, LayoutGrid, Package, Receipt, ScanLine, ShieldCheck, TriangleAlert, Users } from 'lucide-react';
import { usePermissions } from '@/lib/permissions';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
        permission: 'dashboard.read',
    },
    {
        title: 'Sales',
        url: '/ventas',
        icon: Receipt,
        permission: 'sales.read',
    },
    {
        title: 'Catalog',
        url: '/catalogo',
        icon: Package,
        permission: 'catalog.read',
    },
    {
        title: 'Customers',
        url: '/clientes',
        icon: Users,
        permission: 'customer.read',
    },
    {
        title: 'Cash day',
        url: '/caja/dia',
        icon: ScanLine,
        permission: 'cash_day.read',
    },
    {
        title: 'Low stock',
        url: '/reportes/stock',
        icon: TriangleAlert,
        permission: 'report.read',
    },
    {
        title: 'Users',
        url: '/usuarios',
        icon: Users,
        permission: 'user.read',
    },
    {
        title: 'Roles',
        url: '/roles',
        icon: ShieldCheck,
        permission: 'role.read',
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { can } = usePermissions();

    // Las SECCIONES que el rol no puede abrir no se enseñan: el permiso es
    // el MISMO que protege la ruta, y la ruta de un apartado que no se puede
    // abrir solo da un error.
    const items = mainNavItems.filter((item) => item.permission === undefined || can(item.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
