<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Читалка markdown-документов репозитория: спеки, планы, брифы.
 * Нужна, потому что работа идёт на сервере через Claude Code — файл на диске
 * не открыть ни с телефона, ни с ноутбука, а ссылку открыть можно.
 */
class DocsController extends Controller
{
    /**
     * Список всех документов в docs/, сгруппированный по подпапке.
     */
    public function index(): View
    {
        $files = Finder::create()->files()->in(base_path('docs'))->name('*.md')->sortByName();

        /** @var array<string, array<int, array{slug: string, title: string, updated_at: string}>> $groups */
        $groups = [];

        foreach ($files as $file) {
            $slug = Str::of($file->getRelativePathname())->beforeLast('.md')->toString();
            $group = $file->getRelativePath() ?: 'общее';

            $groups[$group][] = [
                'slug' => $slug,
                'title' => $this->titleOf($file->getPathname(), $slug),
                'updated_at' => date('d.m.Y', (int) $file->getMTime()),
            ];
        }

        krsort($groups);

        return view('admin.docs.index', ['groups' => $groups]);
    }

    /**
     * Отрендеренный документ. Slug — путь внутри docs/ без расширения.
     */
    public function show(string $slug): View
    {
        $path = $this->resolve($slug);

        return view('admin.docs.show', [
            'title' => $this->titleOf($path, $slug),
            'slug' => $slug,
            'html' => Str::markdown((string) file_get_contents($path), [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }

    /**
     * Превращает slug в путь к файлу, не выпуская за пределы docs/.
     */
    private function resolve(string $slug): string
    {
        $root = (string) realpath(base_path('docs'));
        $path = realpath($root.'/'.$slug.'.md');

        abort_if($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR), Response::HTTP_NOT_FOUND);

        return (string) $path;
    }

    /**
     * Заголовок из первого markdown-хедера, иначе — сам slug.
     */
    private function titleOf(string $path, string $slug): string
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(substr($line, 2));
            }
        }

        return $slug;
    }
}
