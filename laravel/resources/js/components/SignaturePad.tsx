import { Eraser } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface SignaturePadProps {
    /** Emits the signature as a PNG data URL, or null when cleared/empty. */
    onChange: (dataUrl: string | null) => void;
    className?: string;
}

/**
 * A small canvas signature pad. Captures pointer/touch strokes and emits the
 * result as a PNG data URL. Used on the POD capture screen; the deliver button
 * stays disabled until a signature exists.
 */
export function SignaturePad({ onChange, className }: SignaturePadProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const hasInk = useRef(false);
    // The last non-blank signature we emitted, captured when a stroke ends. A
    // resize redraws from THIS, never from the live canvas, so a burst of
    // resizes can never snapshot a momentarily-cleared backing store.
    const lastGood = useRef<string | null>(null);
    // Monotonic token so that when several resizes overlap, only the newest
    // pending redraw is allowed to finish (older async `onload`s bail out).
    const restoreToken = useRef(0);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const [empty, setEmpty] = useState(true);

    const prepareCanvas = useCallback(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        const ctx = canvas.getContext('2d');
        if (ctx) {
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
        }
    }, []);

    useEffect(() => {
        prepareCanvas();

        const canvas = canvasRef.current;
        if (!canvas || typeof ResizeObserver === 'undefined') {
            return;
        }

        // Re-preparing resizes the backing store, which clears any ink. On mobile
        // a resize is usually just the URL bar showing/hiding, and wiping a
        // captured signature right before the rider taps deliver is hostile. So we
        // snapshot the current drawing, re-prepare, then redraw it scaled to the
        // new size — preserving a completed signature instead of clearing it.
        let first = true;
        const observer = new ResizeObserver(() => {
            if (first) {
                first = false; // the initial observe fires synchronously; skip it
                return;
            }
            const current = canvasRef.current;
            if (!current) {
                return;
            }
            drawing.current = false;

            // Nothing captured to preserve: just re-prepare the (empty) pad.
            // Redraw only from the stored last-good snapshot — never from the
            // live canvas, whose backing store prepareCanvas() clears below.
            if (!hasInk.current || !lastGood.current) {
                prepareCanvas();
                return;
            }

            const token = ++restoreToken.current;
            const snapshot = lastGood.current;
            prepareCanvas();
            const image = new Image();
            image.onload = () => {
                // A newer resize superseded this redraw; drop it so a stale draw
                // can't land on (or emit for) the wrong canvas dimensions.
                if (token !== restoreToken.current) {
                    return;
                }
                const canvasNow = canvasRef.current;
                const ctx = canvasNow?.getContext('2d');
                if (!canvasNow || !ctx) {
                    return;
                }
                const rect = canvasNow.getBoundingClientRect();
                // ctx is scaled by devicePixelRatio in prepareCanvas, so draw in
                // CSS-pixel space; the old image scales to the new dimensions.
                ctx.drawImage(image, 0, 0, rect.width, rect.height);
                // Emit the known-good snapshot, not a fresh read of the canvas:
                // guarantees onChange never receives a blank/transparent PNG.
                onChangeRef.current(snapshot);
            };
            image.src = snapshot;
        });
        observer.observe(canvas);

        return () => observer.disconnect();
    }, [prepareCanvas]);

    const pointFromEvent = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const canvas = canvasRef.current!;
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
        event.preventDefault();
        const ctx = canvasRef.current?.getContext('2d');
        if (!ctx) {
            return;
        }
        drawing.current = true;
        const { x, y } = pointFromEvent(event);
        ctx.beginPath();
        ctx.moveTo(x, y);
        canvasRef.current?.setPointerCapture(event.pointerId);
    };

    const move = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current) {
            return;
        }
        event.preventDefault();
        const ctx = canvasRef.current?.getContext('2d');
        if (!ctx) {
            return;
        }
        const { x, y } = pointFromEvent(event);
        ctx.lineTo(x, y);
        ctx.stroke();
        hasInk.current = true;
    };

    const end = () => {
        if (!drawing.current) {
            return;
        }
        drawing.current = false;
        if (hasInk.current) {
            setEmpty(false);
            const dataUrl = canvasRef.current?.toDataURL('image/png') ?? null;
            if (dataUrl) {
                lastGood.current = dataUrl;
            }
            onChange(dataUrl);
        }
    };

    const clear = () => {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');
        if (canvas && ctx) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
        hasInk.current = false;
        lastGood.current = null;
        // Invalidate any pending resize redraw so it can't re-emit a stale
        // signature after the pad has been cleared.
        restoreToken.current += 1;
        setEmpty(true);
        onChange(null);
    };

    return (
        <div className={cn('grid gap-2', className)}>
            <canvas
                ref={canvasRef}
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerLeave={end}
                className="h-40 w-full touch-none rounded-md border border-dashed border-input bg-muted/30"
                aria-label="Signature pad"
            />
            <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">
                    {empty ? 'Sign above to enable delivery.' : 'Signature captured.'}
                </span>
                <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={empty}>
                    <Eraser className="size-4" />
                    Clear
                </Button>
            </div>
        </div>
    );
}
