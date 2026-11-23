import type { ProjectCommand } from '@/types';

/** A deployment command being edited on the project page (legacy cloneCmd()). */
export interface EditableCommand {
    /** Client-only React key; never sent to the API. */
    uid: number;
    id: number | null;
    name: string;
    command: string;
    is_enabled: boolean;
    stop_on_error: boolean;
    app_state_trigger: 'none' | 'up' | 'down' | string;
    last_exit_code: number | null;
    last_duration_ms: number | null;
    last_run_at: string | null;
}

/** Live highlight of a command card while / after it runs (legacy activeStepStates). */
export type StepState = 'running' | 'success' | 'failed';

let nextUid = 1;

export function newUid(): number {
    return nextUid++;
}

export function cloneCmd(c: Partial<ProjectCommand> & Record<string, unknown>): EditableCommand {
    return {
        uid: newUid(),
        id: (c.id as number | undefined) || null,
        name: (c.name as string) || '',
        command: (c.command as string) || '',
        is_enabled: c.is_enabled !== undefined ? Boolean(c.is_enabled) : true,
        stop_on_error: c.stop_on_error !== undefined ? Boolean(c.stop_on_error) : true,
        app_state_trigger: (c.app_state_trigger as string) || 'none',
        last_exit_code: (c.last_exit_code as number | null | undefined) ?? null,
        last_duration_ms: (c.last_duration_ms as number | null | undefined) ?? null,
        last_run_at: (c.last_run_at as string | null | undefined) ?? null,
    };
}

/** Command payload for save_commands / execute (drops the client-only uid). */
export function toPayload(commands: EditableCommand[]): Record<string, unknown>[] {
    return commands.map(({ uid: _uid, ...rest }) => rest);
}
