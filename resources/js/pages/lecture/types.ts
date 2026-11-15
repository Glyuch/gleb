export type Question = {
    id: string;
    text: string;
    options: string[];
    correctIndex?: number | null;
    explanation?: string;
};
export type Room = {
    code: string;
    expiresAt: string;
    joinUrl: string;
    qrUrl: string;
    stateUrl: string;
    voteUrl: string;
    manageUrl: string | null;
    canManage: boolean;
    question: Question | null;
    revision: number;
    open: boolean;
    revealed: boolean;
    ended: boolean;
    totalVotes: number;
    counts: number[] | null;
    myVote: number | null;
};
export type LectureProps = {
    isPresenter: boolean;
    questions: Question[];
    room: Room | null;
    createRoomUrl: string | null;
};
