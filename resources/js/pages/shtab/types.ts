export type MetricStatus = 'green' | 'yellow' | 'red';

export interface BoardMetric {
    id: number;
    name: string;
    status: MetricStatus;
    value_text: string | null;
}

export type RoleType = 'owner' | 'lead' | 'helper' | 'watcher';
export type LoadStatus = 'free' | 'ok' | 'full' | 'over';

export interface BoardRole {
    key: RoleType;
    label: string;
    short: string;
    default_load: number;
}

export interface PersonAssignment {
    id: number;
    object_id: number;
    object_name: string | null;
    object_emoji: string | null;
    object_focus_level: 0 | 1 | 2;
    role_label: string;
    role_type: RoleType;
    load_percent: number;
    comment: string | null;
    started_at: string;
    days: number;
}

export interface BoardTask {
    id: number;
    title: string;
    is_done: boolean;
    is_key: boolean;
    assignee: {
        id: number;
        name: string;
        initials: string;
        color: string;
    } | null;
}

export interface PersonKeyTask {
    id: number;
    object_name: string | null;
    object_emoji: string | null;
    title: string;
}

export interface BoardPerson {
    id: number;
    name: string;
    initials: string;
    class: string;
    color: string;
    is_direct: boolean;
    manager_id: number | null;
    is_me: boolean;
    assignments: PersonAssignment[];
    focus_count: number;
    hot_count: number;
    owner_count: number;
    load_percent: number;
    load_status: LoadStatus;
    is_overloaded: boolean;
    in_reserve: boolean;
    key_tasks: PersonKeyTask[];
}

export interface ObjectAssignment {
    id: number;
    person_id: number;
    person_name: string | null;
    person_initials: string | null;
    person_color: string | null;
    role_label: string;
    role_type: RoleType;
    load_percent: number;
    started_at: string;
    days: number;
}

export interface BoardObject {
    id: number;
    type: 'product' | 'project' | 'enabler';
    parent_id: number | null;
    name: string;
    description: string | null;
    emoji: string | null;
    focus_level: 0 | 1 | 2;
    color: string;
    metrics: BoardMetric[];
    assignments: ObjectAssignment[];
    owner_name: string | null;
    load_total: number;
    has_owner: boolean;
    tasks: BoardTask[];
    open_tasks: number;
    total_tasks: number;
    is_uncovered: boolean;
    uncovered_days: number | null;
}

export interface Board {
    people: BoardPerson[];
    objects: BoardObject[];
    business_metrics: BoardMetric[];
    roles: BoardRole[];
    capacity_percent: number;
}

export interface ChronicleEvent {
    id: number;
    type: string;
    person: {
        id: number;
        name: string;
        initials: string;
        color: string;
    } | null;
    object: { id: number; name: string; emoji: string | null } | null;
    metric: { id: number; name: string } | null;
    payload: Record<string, unknown> | null;
    comment: string | null;
    created_at: string;
}

export const FIRE: Record<BoardObject['focus_level'], string> = {
    0: '',
    1: '🔥',
    2: '🔥🔥',
};
export const ROLE_ICON: Record<RoleType, string> = {
    owner: '👑',
    lead: '🎯',
    helper: '🤝',
    watcher: '👁',
};
export const TYPE_LABEL: Record<BoardObject['type'], string> = {
    product: 'продукт',
    project: 'проект',
    enabler: 'энейблер',
};
export const LOAD_TONE: Record<
    LoadStatus,
    { bar: string; text: string; bg: string }
> = {
    free: { bar: 'bg-slate-300', text: 'text-slate-500', bg: 'bg-slate-50' },
    ok: {
        bar: 'bg-emerald-500',
        text: 'text-emerald-700',
        bg: 'bg-emerald-50',
    },
    full: { bar: 'bg-amber-500', text: 'text-amber-700', bg: 'bg-amber-50' },
    over: { bar: 'bg-red-500', text: 'text-red-700', bg: 'bg-red-50' },
};

export const STATUS_DOT: Record<MetricStatus, string> = {
    green: 'bg-green-500',
    yellow: 'bg-yellow-500',
    red: 'bg-red-500',
};
