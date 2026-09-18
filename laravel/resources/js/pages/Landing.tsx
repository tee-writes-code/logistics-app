import { Link } from 'react-router-dom';
import { PackageCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export default function Landing() {
    return (
        <main className="mx-auto flex min-h-svh max-w-2xl flex-col items-center justify-center gap-8 p-6">
            <div className="flex flex-col items-center gap-3 text-center">
                <PackageCheck className="size-10 text-primary" />
                <h1 className="text-3xl font-semibold tracking-tight">Logistics App</h1>
                <p className="text-muted-foreground max-w-md">
                    Client-side React SPA scaffold. Screens and roles are wired up in later
                    iterations.
                </p>
            </div>

            <Card className="w-full">
                <CardHeader>
                    <CardTitle>Scaffold ready</CardTitle>
                    <CardDescription>
                        React, TypeScript, Tailwind v4 and shadcn are configured and building.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-wrap gap-3">
                    <Button render={<Link to="/style-check">View style check</Link>} />
                    <Button
                        variant="outline"
                        render={
                            <a href="https://ui.shadcn.com" target="_blank" rel="noreferrer">
                                shadcn docs
                            </a>
                        }
                    />
                </CardContent>
            </Card>
        </main>
    );
}
