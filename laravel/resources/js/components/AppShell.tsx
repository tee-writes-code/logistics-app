import { PackageCheck, LogOut } from 'lucide-react';
import { type ReactNode } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';

import { NotificationBell } from '@/components/NotificationBell';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuth } from '@/lib/auth';
import { cn } from '@/lib/utils';
import type { UserRole } from '@/lib/types';

interface NavItem {
    to: string;
    label: string;
    end?: boolean;
}

const NAV_BY_ROLE: Record<UserRole, NavItem[]> = {
    customer: [
        { to: '/jobs', label: 'Jobs', end: true },
        { to: '/jobs/new', label: 'Book a job' },
        { to: '/pickup-sites', label: 'Pickup sites' },
        { to: '/saved-drops', label: 'Saved drops' },
        { to: '/agent-asks', label: 'Confirmations' },
    ],
    rider: [{ to: '/rider', label: 'My runs' }],
    ops: [
        { to: '/ops/board', label: 'Board' },
        { to: '/ops/riders', label: 'Riders' },
        { to: '/ops/workbench', label: 'Workbench' },
        { to: '/ops/sms', label: 'SMS outbox' },
        { to: '/ops/demo', label: 'Demo' },
    ],
};

export function AppShell({ children }: { children: ReactNode }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    if (!user) {
        return null;
    }

    const nav = NAV_BY_ROLE[user.role];
    const initials = user.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    const handleLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    return (
        <div className="min-h-svh bg-background">
            <header className="border-b">
                <div className="mx-auto flex h-14 max-w-5xl items-center gap-6 px-4">
                    <span className="flex items-center gap-2 font-semibold">
                        <PackageCheck className="size-5 text-primary" />
                        Logistics
                    </span>
                    <nav className="flex flex-1 items-center gap-1 overflow-x-auto">
                        {nav.map((item) => (
                            <NavLink
                                key={item.to}
                                to={item.to}
                                end={item.end}
                                className={({ isActive }) =>
                                    cn(
                                        'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                                        isActive
                                            ? 'bg-accent text-accent-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )
                                }
                            >
                                {item.label}
                            </NavLink>
                        ))}
                    </nav>
                    <NotificationBell />
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button variant="outline" size="sm" className="gap-2">
                                    <span className="flex size-6 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">
                                        {initials}
                                    </span>
                                    <span className="hidden sm:inline">{user.name}</span>
                                </Button>
                            }
                        />
                        <DropdownMenuContent align="end">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    <div className="flex flex-col">
                                        <span>{user.name}</span>
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {user.email}
                                        </span>
                                    </div>
                                </DropdownMenuLabel>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={handleLogout}>
                                <LogOut className="size-4" />
                                Log out
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8">{children}</main>
        </div>
    );
}
