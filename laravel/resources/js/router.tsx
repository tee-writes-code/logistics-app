import { createHashRouter, Link, Navigate, Outlet } from 'react-router-dom';

import { AppShell } from '@/components/AppShell';
import { Toaster } from '@/components/ui/sonner';
import { AuthProvider, homePathForRole, RequireAuth, useAuth } from '@/lib/auth';
import LoginPage from '@/pages/LoginPage';
import StyleCheck from '@/pages/StyleCheck';
import AgentAsks from '@/pages/customer/AgentAsks';
import JobDetail from '@/pages/customer/JobDetail';
import JobList from '@/pages/customer/JobList';
import JobNew from '@/pages/customer/JobNew';
import PickupSites from '@/pages/customer/PickupSites';
import SavedDrops from '@/pages/customer/SavedDrops';
import DemoPanel from '@/pages/ops/DemoPanel';
import JobsBoard from '@/pages/ops/JobsBoard';
import JobDetailOps from '@/pages/ops/JobDetailOps';
import Riders from '@/pages/ops/Riders';
import SmsOutbox from '@/pages/ops/SmsOutbox';
import Workbench from '@/pages/ops/Workbench';
import Track from '@/pages/recipient/Track';
import ActiveJob from '@/pages/rider/ActiveJob';
import RiderQueue from '@/pages/rider/Queue';

/** Provides auth context + global toaster to every route. */
function RootLayout() {
    return (
        <AuthProvider>
            <Outlet />
            <Toaster />
        </AuthProvider>
    );
}

/**
 * Recipient magic-link layout: unauthenticated and standalone. Deliberately
 * outside AuthProvider so opening a track link never probes /user or bounces an
 * unauthenticated recipient to the login screen. Token context comes from the URL.
 */
function RecipientLayout() {
    return (
        <>
            <Outlet />
            <Toaster />
        </>
    );
}

/** Sends "/" to login or the role home once auth is known. */
function IndexRedirect() {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="flex min-h-svh items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    return <Navigate to={user ? homePathForRole(user.role) : '/login'} replace />;
}

function CustomerLayout() {
    return (
        <RequireAuth role="customer">
            <AppShell>
                <Outlet />
            </AppShell>
        </RequireAuth>
    );
}

function RiderLayout() {
    return (
        <RequireAuth role="rider">
            <AppShell>
                <Outlet />
            </AppShell>
        </RequireAuth>
    );
}

function OpsLayout() {
    return (
        <RequireAuth role="ops">
            <AppShell>
                <Outlet />
            </AppShell>
        </RequireAuth>
    );
}

function NotFound() {
    return (
        <div className="mx-auto flex min-h-svh max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
            <h1 className="text-2xl font-semibold">Page not found</h1>
            <Link className="text-primary underline underline-offset-4" to="/">
                Back to home
            </Link>
        </div>
    );
}

export const router = createHashRouter([
    {
        element: <RootLayout />,
        children: [
            { index: true, element: <IndexRedirect /> },
            { path: 'login', element: <LoginPage /> },
            { path: 'style-check', element: <StyleCheck /> },
            {
                element: <CustomerLayout />,
                children: [
                    { path: 'jobs', element: <JobList /> },
                    { path: 'jobs/new', element: <JobNew /> },
                    { path: 'jobs/:id', element: <JobDetail /> },
                    { path: 'pickup-sites', element: <PickupSites /> },
                    { path: 'saved-drops', element: <SavedDrops /> },
                    { path: 'agent-asks', element: <AgentAsks /> },
                ],
            },
            {
                element: <RiderLayout />,
                children: [
                    { path: 'rider', element: <RiderQueue /> },
                    { path: 'rider/queue', element: <RiderQueue /> },
                    { path: 'rider/jobs/:id', element: <ActiveJob /> },
                ],
            },
            {
                element: <OpsLayout />,
                children: [
                    { path: 'ops', element: <JobsBoard /> },
                    { path: 'ops/board', element: <JobsBoard /> },
                    { path: 'ops/jobs/:id', element: <JobDetailOps /> },
                    { path: 'ops/riders', element: <Riders /> },
                    { path: 'ops/workbench', element: <Workbench /> },
                    { path: 'ops/sms', element: <SmsOutbox /> },
                    { path: 'ops/demo', element: <DemoPanel /> },
                ],
            },
            { path: '*', element: <NotFound /> },
        ],
    },
    {
        element: <RecipientLayout />,
        children: [{ path: 'track/:token', element: <Track /> }],
    },
]);
