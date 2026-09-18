import { RouterProvider } from 'react-router-dom';

import { TooltipProvider } from '@/components/ui/tooltip';
import { router } from '@/router';

export default function App() {
    return (
        <TooltipProvider>
            <RouterProvider router={router} />
        </TooltipProvider>
    );
}
