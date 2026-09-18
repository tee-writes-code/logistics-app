import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

/**
 * Shown when a same-day booking cannot finish today. Nothing has been booked
 * yet: confirming books it for the next window, cancelling leaves no job.
 */
export function OfferNextDialog({
    open,
    onConfirm,
    onCancel,
    pending,
}: {
    open: boolean;
    onConfirm: () => void;
    onCancel: () => void;
    pending?: boolean;
}) {
    return (
        <AlertDialog open={open} onOpenChange={(next) => (next ? undefined : onCancel())}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Same-day delivery isn’t possible</AlertDialogTitle>
                    <AlertDialogDescription>
                        There isn’t enough time left today to complete this delivery. We can book it
                        for the next available window instead. Nothing has been booked yet.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel onClick={onCancel} disabled={pending}>
                        Keep editing
                    </AlertDialogCancel>
                    {/*
                     * A plain Button, not AlertDialogAction: the latter wraps Base
                     * UI's AlertDialog.Close, which ignores event.preventDefault()
                     * and always auto-dismisses, which would fire onCancel and
                     * destroy the retry surface mid-submit. This keeps the dialog
                     * open (controlled by the caller) until the re-submit resolves.
                     */}
                    <Button
                        type="button"
                        onClick={onConfirm}
                        disabled={pending}
                    >
                        Book next window
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
