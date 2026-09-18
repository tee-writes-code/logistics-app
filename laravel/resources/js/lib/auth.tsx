import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { Navigate } from 'react-router-dom';

import { ApiError, get, post, setUnauthorizedHandler } from '@/lib/api';
import type { AuthUser, UserRole } from '@/lib/types';

interface AuthContextValue {
    user: AuthUser | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<AuthUser>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** The landing route for each role after login. */
export function homePathForRole(role: UserRole): string {
    switch (role) {
        case 'customer':
            return '/jobs';
        case 'rider':
            return '/rider';
        case 'ops':
            return '/ops';
    }
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        try {
            const { user: current } = await get<{ user: AuthUser | null }>('/user');
            setUser(current);
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                setUser(null);
            } else {
                throw error;
            }
        } finally {
            setLoading(false);
        }
    }, []);

    const login = useCallback(async (email: string, password: string) => {
        const { user: current } = await post<{ user: AuthUser }>('/login', { email, password });
        setUser(current);
        return current;
    }, []);

    const logout = useCallback(async () => {
        try {
            await post('/logout');
        } finally {
            setUser(null);
        }
    }, []);

    useEffect(() => {
        setUnauthorizedHandler(() => setUser(null));
        void refresh();
        return () => setUnauthorizedHandler(null);
    }, [refresh]);

    const value = useMemo<AuthContextValue>(
        () => ({ user, loading, login, logout, refresh }),
        [user, loading, login, logout, refresh],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (context === null) {
        throw new Error('useAuth must be used within an AuthProvider.');
    }
    return context;
}

/**
 * Guards a route group. Redirects unauthenticated users to /login, and users
 * whose role is not allowed to their own role home.
 */
export function RequireAuth({
    role,
    children,
}: {
    role?: UserRole | UserRole[];
    children: ReactNode;
}) {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="flex min-h-svh items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (role !== undefined) {
        const allowed = Array.isArray(role) ? role : [role];
        if (!allowed.includes(user.role)) {
            return <Navigate to={homePathForRole(user.role)} replace />;
        }
    }

    return <>{children}</>;
}
