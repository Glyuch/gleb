import { Label } from '@/components/ui/label';
import type { BoardRole, RoleType } from '../types';
import { ROLE_ICON } from '../types';

const LOAD_PRESETS: Array<[number, string]> = [
    [100, 'целиком'],
    [75, 'бо́льшая часть'],
    [50, 'половина'],
    [25, 'частично'],
    [10, 'по краю'],
];

interface Props {
    roles: BoardRole[];
    roleType: RoleType;
    loadPercent: number;
    onRoleType: (role: RoleType) => void;
    onLoadPercent: (load: number) => void;
}

/**
 * Общий блок «тип участия + вовлечённость»: используется и при назначении,
 * и при правке уже активного назначения. Смена роли подставляет её дефолтную
 * долю — дальше её можно докрутить пресетами или ползунком.
 */
export default function RoleFields({
    roles,
    roleType,
    loadPercent,
    onRoleType,
    onLoadPercent,
}: Props) {
    return (
        <>
            <div>
                <Label>Тип участия</Label>
                <div className="flex flex-wrap gap-1.5">
                    {roles.map((role) => (
                        <button
                            key={role.key}
                            type="button"
                            onClick={() => {
                                onRoleType(role.key);
                                onLoadPercent(role.default_load);
                            }}
                            className={`cursor-pointer rounded-lg border px-2 py-1 text-xs font-bold ${roleType === role.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300 text-gray-700'}`}
                        >
                            {ROLE_ICON[role.key]} {role.label}
                        </button>
                    ))}
                </div>
            </div>
            <div>
                <Label htmlFor="load_percent">
                    Вовлечённость — {loadPercent}% времени
                </Label>
                <div className="mb-1.5 flex flex-wrap gap-1.5">
                    {LOAD_PRESETS.map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => onLoadPercent(value)}
                            className={`cursor-pointer rounded-full border px-2 py-0.5 text-[10px] font-bold ${loadPercent === value ? 'border-gray-900 bg-gray-100 text-gray-900' : 'border-gray-300 text-gray-500'}`}
                        >
                            {value}% · {label}
                        </button>
                    ))}
                </div>
                <input
                    id="load_percent"
                    type="range"
                    min={0}
                    max={100}
                    step={5}
                    value={loadPercent}
                    onChange={(e) => onLoadPercent(Number(e.target.value))}
                    className="w-full cursor-pointer"
                />
            </div>
        </>
    );
}
