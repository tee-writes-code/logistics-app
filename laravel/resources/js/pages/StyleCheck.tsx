import { Link } from 'react-router-dom';
import { ArrowLeft, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

export default function StyleCheck() {
    return (
        <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-8 p-6">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold tracking-tight">Style check</h1>
                <Button variant="ghost" size="sm" asChild>
                    <Link to="/">
                        <ArrowLeft />
                        Home
                    </Link>
                </Button>
            </div>

            <section className="flex flex-col gap-3">
                <h2 className="text-sm font-medium text-muted-foreground">Buttons</h2>
                <div className="flex flex-wrap gap-3">
                    <Button>Default</Button>
                    <Button variant="secondary">Secondary</Button>
                    <Button variant="outline">Outline</Button>
                    <Button variant="destructive">Destructive</Button>
                    <Button variant="ghost">Ghost</Button>
                    <Button variant="link">Link</Button>
                    <Button size="icon" aria-label="Truck">
                        <Truck />
                    </Button>
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-sm font-medium text-muted-foreground">Badges</h2>
                <div className="flex flex-wrap gap-3">
                    <Badge>Default</Badge>
                    <Badge variant="secondary">Secondary</Badge>
                    <Badge variant="outline">Outline</Badge>
                    <Badge variant="destructive">Destructive</Badge>
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-sm font-medium text-muted-foreground">Input</h2>
                <div className="flex max-w-sm flex-col gap-3">
                    <Input placeholder="Tracking number" aria-label="Tracking number" />
                    <Input type="email" placeholder="you@example.com" aria-label="Email" />
                    <Input placeholder="Disabled" disabled />
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-sm font-medium text-muted-foreground">Card</h2>
                <Card>
                    <CardHeader>
                        <CardTitle>Delivery #1042</CardTitle>
                        <CardDescription>A sample card composed from shadcn primitives.</CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        Tailwind v4 tokens, the <code>cn</code> helper, and CVA variants all render
                        here.
                    </CardContent>
                    <CardFooter className="gap-3">
                        <Button size="sm">Confirm</Button>
                        <Badge variant="secondary">In transit</Badge>
                    </CardFooter>
                </Card>
            </section>
        </main>
    );
}
