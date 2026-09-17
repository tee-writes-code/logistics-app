import { createHashRouter, Link, RouterProvider } from 'react-router-dom';

import Landing from '@/pages/Landing';
import StyleCheck from '@/pages/StyleCheck';

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

const router = createHashRouter([
    { path: '/', element: <Landing /> },
    { path: '/style-check', element: <StyleCheck /> },
    { path: '*', element: <NotFound /> },
]);

export default function App() {
    return <RouterProvider router={router} />;
}
