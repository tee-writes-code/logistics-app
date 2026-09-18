import { zodResolver } from '@hookform/resolvers/zod';
import { PackageCheck } from 'lucide-react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { z } from 'zod';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { homePathForRole, useAuth } from '@/lib/auth';

const schema = z.object({
    email: z.string().email('Enter a valid email.'),
    password: z.string().min(1, 'Password is required.'),
});

type LoginValues = z.infer<typeof schema>;

export default function LoginPage() {
    const { user, loading, login } = useAuth();
    const navigate = useNavigate();

    const form = useForm<LoginValues>({
        resolver: zodResolver(schema),
        defaultValues: { email: '', password: '' },
    });

    useEffect(() => {
        if (!loading && user) {
            navigate(homePathForRole(user.role), { replace: true });
        }
    }, [loading, user, navigate]);

    const onSubmit = async (values: LoginValues) => {
        try {
            const current = await login(values.email, values.password);
            navigate(homePathForRole(current.role), { replace: true });
        } catch (error) {
            if (error instanceof ApiError && error.errors?.email) {
                form.setError('email', { message: error.errors.email[0] });
            } else {
                toast.error(error instanceof ApiError ? error.message : 'Could not sign in.');
            }
        }
    };

    return (
        <main className="mx-auto flex min-h-svh max-w-md flex-col items-center justify-center gap-6 p-6">
            <div className="flex flex-col items-center gap-2 text-center">
                <PackageCheck className="size-9 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Logistics</h1>
            </div>
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>Sign in</CardTitle>
                    <CardDescription>Use your account to access your deliveries.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Form {...form}>
                        <form className="grid gap-4" onSubmit={form.handleSubmit(onSubmit)}>
                            <FormField
                                control={form.control}
                                name="email"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Email</FormLabel>
                                        <FormControl>
                                            <Input
                                                type="email"
                                                autoComplete="username"
                                                placeholder="you@example.com"
                                                {...field}
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="password"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Password</FormLabel>
                                        <FormControl>
                                            <Input
                                                type="password"
                                                placeholder="••••••••"
                                                autoComplete="current-password"
                                                {...field}
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                {form.formState.isSubmitting ? 'Signing in…' : 'Sign in'}
                            </Button>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </main>
    );
}
