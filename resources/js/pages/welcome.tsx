import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="gleb.finance" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <h1 className="text-4xl font-semibold tracking-tight lg:text-6xl">gleb.finance</h1>
            </div>
        </>
    );
}
