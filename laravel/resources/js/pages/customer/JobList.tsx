import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { toast } from 'sonner';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { get } from '@/lib/api';
import type { DataResponse, Job } from '@/lib/types';

const WINDOW_LABEL: Record<Job['window'], string> = {
    same_day: 'Same day',
    next: 'Next window',
};

export default function JobList() {
    const [jobs, setJobs] = useState<Job[] | null>(null);
    const navigate = useNavigate();

    useEffect(() => {
        get<DataResponse<Job[]>>('/jobs')
            .then((response) => setJobs(response.data))
            .catch(() => toast.error('Could not load your jobs.'));
    }, []);

    return (
        <div className="grid gap-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Your jobs</h1>
                    <p className="text-muted-foreground">Track and manage your deliveries.</p>
                </div>
                <Button
                    render={
                        <Link to="/jobs/new">
                            <Plus className="size-4" />
                            Book a job
                        </Link>
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>All jobs</CardTitle>
                </CardHeader>
                <CardContent>
                    {jobs === null ? (
                        <p className="text-muted-foreground">Loading…</p>
                    ) : jobs.length === 0 ? (
                        <p className="text-muted-foreground">
                            No jobs yet. Book your first delivery to get started.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>#</TableHead>
                                    <TableHead>Drop-off</TableHead>
                                    <TableHead>Window</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {jobs.map((job) => (
                                    <TableRow
                                        key={job.id}
                                        className="cursor-pointer"
                                        onClick={() => navigate(`/jobs/${job.id}`)}
                                    >
                                        <TableCell className="font-medium">{job.id}</TableCell>
                                        <TableCell>
                                            <div>{job.drop_contact_name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {job.drop_address}
                                            </div>
                                        </TableCell>
                                        <TableCell>{WINDOW_LABEL[job.window]}</TableCell>
                                        <TableCell>
                                            <JobStatusBadge status={job.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
