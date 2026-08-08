export type MetricStatus = 'green' | 'yellow' | 'red';

export interface BoardMetric {
    id: number;
    name: string;
    status: MetricStatus;
    value_text: string | null;
}

export interface PersonAssignment {
    id: number;
    object_id: number;
    object_name: string | null;
    object_emoji: string | null;
    role_label: string;
    comment: string | null;
    started_at: string;
    days: number;
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
    is_overloaded: boolean;
    in_reserve: boolean;
}

export interface ObjectAssignment {
    id: number;
    person_id: number;
    person_name: string | null;
    person_initials: string | null;
    person_color: string | null;
    role_label: string;
    started_at: string;
    days: number;
}

export interface BoardObject {
    id: number;
    type: 'product' | 'project' | 'enabler';
    parent_id: number | null;
    name: string;
    emoji: string | null;
    focus_level: 0 | 1 | 2;
    color: string;
    metrics: BoardMetric[];
    assignments: ObjectAssignment[];
    is_uncovered: boolean;
    uncovered_days: number | null;
}

export interface Board {
    people: BoardPerson[];
    objects: BoardObject[];
    business_metrics: BoardMetric[];
}

export interface ChronicleEvent {
    id: number;
    type: string;
    person: { id: number; name: string; initials: string; color: string } | null;
    object: { id: number; name: string; emoji: string | null } | null;
    metric: { id: number; name: string } | null;
    payload: Record<string, unknown> | null;
    comment: string | null;
    created_at: string;
}

export const FIRE: Record<number, string> = { 0: '', 1: '🔥', 2: '🔥🔥' };
export const STATUS_DOT: Record<MetricStatus, string> = {
    green: 'bg-green-500',
    yellow: 'bg-yellow-500',
    red: 'bg-red-500',
};
