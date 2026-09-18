/**
 * Thin fetch wrapper for the JSON API behind Sanctum SPA (cookie) auth.
 *
 * - Sends/accepts JSON and includes the session cookie.
 * - Attaches the X-XSRF-TOKEN header from the XSRF-TOKEN cookie, refreshing the
 *   CSRF cookie once and retrying on a 419.
 * - On a 401 it clears auth and routes to /#/login, but only when an unauthorized
 *   handler is registered (session contexts); token/recipient routes are exempt.
 *
 * This module is shared and owned here; later iterations import it, never fork.
 */

const API_BASE = '/api';

export interface ValidationErrors {
    [field: string]: string[];
}

export class ApiError extends Error {
    readonly status: number;
    readonly errors?: ValidationErrors;
    readonly payload: unknown;

    constructor(status: number, message: string, payload: unknown, errors?: ValidationErrors) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.payload = payload;
        this.errors = errors;
    }
}

type UnauthorizedHandler = () => void;

let onUnauthorized: UnauthorizedHandler | null = null;

/** Register a callback invoked when the API returns 401 (e.g. clear auth state). */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
    onUnauthorized = handler;
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** Ensure a fresh CSRF cookie exists before a mutating request. */
async function ensureCsrfCookie(force = false): Promise<void> {
    if (!force && readCookie('XSRF-TOKEN')) {
        return;
    }
    await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
}

function buildHeaders(hasBody: boolean): Headers {
    const headers = new Headers({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    });
    if (hasBody) {
        headers.set('Content-Type', 'application/json');
    }
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) {
        headers.set('X-XSRF-TOKEN', xsrf);
    }
    return headers;
}

async function parse(response: Response): Promise<unknown> {
    if (response.status === 204 || response.headers.get('Content-Length') === '0') {
        return undefined;
    }
    const text = await response.text();
    if (text === '') {
        return undefined;
    }
    try {
        return JSON.parse(text);
    } catch {
        return text;
    }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';

async function request<T>(method: Method, path: string, body?: unknown, isRetry = false): Promise<T> {
    const mutating = method !== 'GET';
    if (mutating) {
        await ensureCsrfCookie();
    }

    const hasBody = body !== undefined;
    const response = await fetch(`${API_BASE}${path}`, {
        method,
        credentials: 'same-origin',
        headers: buildHeaders(hasBody),
        body: hasBody ? JSON.stringify(body) : undefined,
    });

    // CSRF token mismatch: refresh the cookie once and retry.
    if (response.status === 419 && !isRetry) {
        await ensureCsrfCookie(true);
        return request<T>(method, path, body, true);
    }

    if (response.status === 401) {
        // Only bounce to login for session-authenticated contexts, i.e. when an
        // unauthorized handler is registered (inside AuthProvider). Recipient /
        // token routes (track/{token}, jobs/{job}/position) render outside the
        // provider, so a 401 there must not strand them on a dead login screen.
        if (onUnauthorized) {
            onUnauthorized();
            if (!window.location.hash.startsWith('#/login')) {
                window.location.hash = '#/login';
            }
        }
        throw new ApiError(401, 'Unauthenticated.', undefined);
    }

    const data = await parse(response);

    if (!response.ok) {
        const record = (data ?? {}) as { message?: string; errors?: ValidationErrors };
        throw new ApiError(
            response.status,
            record.message ?? `Request failed with status ${response.status}`,
            data,
            record.errors,
        );
    }

    return data as T;
}

export function get<T>(path: string): Promise<T> {
    return request<T>('GET', path);
}

export function post<T>(path: string, body?: unknown): Promise<T> {
    return request<T>('POST', path, body);
}

export function patch<T>(path: string, body?: unknown): Promise<T> {
    return request<T>('PATCH', path, body);
}

export function put<T>(path: string, body?: unknown): Promise<T> {
    return request<T>('PUT', path, body);
}

export function del<T>(path: string): Promise<T> {
    return request<T>('DELETE', path);
}

export const api = { get, post, patch, put, del };
