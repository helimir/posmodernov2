import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    /** The SLUGS of the permissions the user has: the same ones the routes ask for. */
    permissions: string[];
    /** The role of the user, or `null` when it has none. */
    role: { id: number; name: string; slug: string } | null;
    /** Whether the user is the superuser: the role that can do everything. */
    is_superuser: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** The SLUG of the permission this section needs: the entry is hidden without it. */
    permission?: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
