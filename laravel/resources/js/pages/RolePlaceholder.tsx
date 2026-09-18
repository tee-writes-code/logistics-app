import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/lib/auth';

/**
 * Minimal landing shell for non-customer roles. Rider, recipient, and ops
 * experiences are built in later iterations.
 */
export default function RolePlaceholder() {
    const { user } = useAuth();

    return (
        <Card>
            <CardHeader>
                <CardTitle>Welcome, {user?.name}</CardTitle>
                <CardDescription>
                    Your {user?.role} workspace is coming in a later iteration.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p className="text-muted-foreground">
                    There’s nothing to do here yet. Customer booking is live today.
                </p>
            </CardContent>
        </Card>
    );
}
