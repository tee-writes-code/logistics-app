import { Link } from 'react-router-dom';
import { ArrowLeft, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const FRUIT_ITEMS: Record<string, string> = {
    apple: 'Apple',
    banana: 'Banana',
    cherry: 'Cherry',
};

export default function StyleCheck() {
    return (
        <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-8 p-6">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold tracking-tight">Style check</h1>
                <Button
                    variant="ghost"
                    size="sm"
                    render={
                        <Link to="/">
                            <ArrowLeft />
                            Home
                        </Link>
                    }
                />
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
                <h2 className="text-sm font-medium text-muted-foreground">
                    Overlays &amp; controls
                </h2>
                <div className="flex flex-wrap items-center gap-4">
                    <Dialog>
                        <DialogTrigger render={<Button variant="outline">Open dialog</Button>} />
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Dialog title</DialogTitle>
                                <DialogDescription>
                                    A Base UI dialog composed through the shadcn wrapper.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter showCloseButton />
                        </DialogContent>
                    </Dialog>

                    <DropdownMenu>
                        <DropdownMenuTrigger render={<Button variant="outline">Open menu</Button>} />
                        <DropdownMenuContent align="start">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                <DropdownMenuItem>Edit</DropdownMenuItem>
                                <DropdownMenuItem>Duplicate</DropdownMenuItem>
                                <DropdownMenuItem variant="destructive">Delete</DropdownMenuItem>
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Select items={FRUIT_ITEMS}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Pick a fruit" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="apple">Apple</SelectItem>
                            <SelectItem value="banana">Banana</SelectItem>
                            <SelectItem value="cherry">Cherry</SelectItem>
                        </SelectContent>
                    </Select>

                    <Tooltip>
                        <TooltipTrigger render={<Button variant="outline">Hover me</Button>} />
                        <TooltipContent>Helpful hint</TooltipContent>
                    </Tooltip>
                </div>

                <div className="flex flex-wrap items-center gap-6">
                    <div className="flex items-center gap-2">
                        <Switch id="notify" defaultChecked />
                        <Label htmlFor="notify">Notifications</Label>
                    </div>
                    <RadioGroup defaultValue="one" className="flex-row gap-4">
                        <div className="flex items-center gap-2">
                            <RadioGroupItem value="one" id="opt-one" />
                            <Label htmlFor="opt-one">One</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <RadioGroupItem value="two" id="opt-two" />
                            <Label htmlFor="opt-two">Two</Label>
                        </div>
                    </RadioGroup>
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
                        <Separator className="my-3" />
                        Base UI primitives back every control above.
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
