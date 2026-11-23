import { createContext, type ReactNode, useCallback, useContext, useMemo, useState } from 'react';
import type { Project, SshProfile } from '@/types';
import EnvModal from './env-modal';
import ProjectModal from './project-modal';
import ReleasesModal from './releases-modal';
import RemoteLogsModal from './remote-logs-modal';
import ServerPulseModal from './server-pulse-modal';
import SettingsModal from './settings-modal';

/**
 * Shared modals (legacy window.DeployerApp.open*Modal). Each modal has its own slot,
 * so one can open on top of another (e.g. Settings over the project editor).
 * A slot is `null` when that modal is closed.
 */

export type ProjectModalMode = 'create' | 'edit' | 'duplicate';

export interface ProjectModalState {
    /** null when creating a new project; the source project when duplicating. */
    project: Project | null;
    mode: ProjectModalMode;
    /** Open with the SSH connection section focused (legacy optionsOrFocusSsh === true). */
    focusSsh?: boolean;
    /** Called with the saved project (create / update / duplicate) and the API response. */
    onSaved?: (project: Project, response: unknown) => void;
}

export interface ProjectRefState {
    project: Project;
}

export interface ReleasesModalState {
    project: Project;
    /** Called after a successful rollback. */
    onDone?: (result: unknown) => void;
}

export interface RemoteLogsModalState {
    project: Project;
    preferredFile?: string | null;
}

export type SettingsTab = 'profiles' | 'notifications' | 'checker' | 'alerts' | 'vault' | string;

export interface SettingsModalState {
    tab: SettingsTab;
}

/** Props every modal component receives. */
export interface ModalProps<S> {
    state: S;
    onClose: () => void;
}

interface ModalsApi {
    openProjectModal: (project?: Project | null, onSaved?: ProjectModalState['onSaved'], options?: { mode?: ProjectModalMode; focusSsh?: boolean }) => void;
    openDuplicateModal: (source: Project, onSaved?: ProjectModalState['onSaved']) => void;
    openServerPulseModal: (project: Project) => void;
    openRemoteEnvModal: (project: Project) => void;
    openReleasesModal: (project: Project, onDone?: ReleasesModalState['onDone']) => void;
    openRemoteLogsModal: (project: Project, preferredFile?: string | null) => void;
    openSettingsModal: (tab?: SettingsTab) => void;

    /** Projects offered as "clone commands from" templates in the project modal (legacy setAvailableProjects). */
    availableProjects: Project[];
    setAvailableProjects: (projects: Project[]) => void;
    /** Shared SSH profiles offered in the project modal (legacy setAvailableProfiles). */
    availableProfiles: SshProfile[];
    setAvailableProfiles: (profiles: SshProfile[]) => void;
}

const ModalsContext = createContext<ModalsApi | null>(null);

export function useModals(): ModalsApi {
    const ctx = useContext(ModalsContext);
    if (!ctx) throw new Error('useModals must be used inside <ModalsProvider>');
    return ctx;
}

export function ModalsProvider({ children }: { children: ReactNode }) {
    const [projectModal, setProjectModal] = useState<ProjectModalState | null>(null);
    const [pulse, setPulse] = useState<ProjectRefState | null>(null);
    const [env, setEnv] = useState<ProjectRefState | null>(null);
    const [releases, setReleases] = useState<ReleasesModalState | null>(null);
    const [logs, setLogs] = useState<RemoteLogsModalState | null>(null);
    const [settings, setSettings] = useState<SettingsModalState | null>(null);
    const [availableProjects, setAvailableProjects] = useState<Project[]>([]);
    const [availableProfiles, setAvailableProfiles] = useState<SshProfile[]>([]);

    const openProjectModal = useCallback<ModalsApi['openProjectModal']>((project = null, onSaved, options = {}) => {
        setProjectModal({ project, onSaved, mode: options.mode ?? (project ? 'edit' : 'create'), focusSsh: options.focusSsh });
    }, []);

    const api = useMemo<ModalsApi>(
        () => ({
            openProjectModal,
            openDuplicateModal: (source, onSaved) => setProjectModal({ project: source, onSaved, mode: 'duplicate' }),
            openServerPulseModal: (project) => setPulse({ project }),
            openRemoteEnvModal: (project) => setEnv({ project }),
            openReleasesModal: (project, onDone) => setReleases({ project, onDone }),
            openRemoteLogsModal: (project, preferredFile = null) => setLogs({ project, preferredFile }),
            openSettingsModal: (tab = 'profiles') => setSettings({ tab }),
            availableProjects,
            setAvailableProjects,
            availableProfiles,
            setAvailableProfiles,
        }),
        [openProjectModal, availableProjects, availableProfiles],
    );

    return (
        <ModalsContext.Provider value={api}>
            {children}
            {projectModal && <ProjectModal state={projectModal} onClose={() => setProjectModal(null)} />}
            {pulse && <ServerPulseModal state={pulse} onClose={() => setPulse(null)} />}
            {env && <EnvModal state={env} onClose={() => setEnv(null)} />}
            {releases && <ReleasesModal state={releases} onClose={() => setReleases(null)} />}
            {logs && <RemoteLogsModal state={logs} onClose={() => setLogs(null)} />}
            {settings && <SettingsModal state={settings} onClose={() => setSettings(null)} />}
        </ModalsContext.Provider>
    );
}
