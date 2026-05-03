<?php
/**
 * Mini-markdown parser za blog.
 *
 * Poenostavljen, samostojen parser, ki pokriva markdown elemente, ki jih
 * tipično generira Claude / piše človek pri marketing članku:
 *   - Naslovi: # H1, ## H2, ### H3, #### H4
 *   - Odstavki, prazne vrstice
 *   - Bold (**…** ali __…__), italic (*…* ali _…_)
 *   - Inline code (`…`), code block (``` … ```)
 *   - Linki [text](url) in slike ![alt](src) ali ![alt](media:ID)
 *   - Sezname: ul (-, *, +) in ol (1.)
 *   - Citate (>)
 *   - Horizontalna linija (---)
 *   - Custom shortcodes [cta:type], ki ostanejo v output-u kot
 *     <!--CTA:type--> placeholder za post-process v blog_render_md().
 *
 * Output je HTML niz brez DOCTYPE/wrapperja. Sanitizacija se izvede
 * dodatno v blog_helpers.php (allowlist tagov).
 *
 * Avtor: Rezble (interno, brez external dep).
 */

class Parsedown {

    /** Pretvori markdown niz v HTML. */
    public function text(string $md): string {
        // Normalizacija newline
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $md = trim($md, "\n");

        // 1. Izvleci fenced code blocks (```), da jih kasnejši pravila ne mašijo
        $codeBlocks = [];
        $md = preg_replace_callback(
            '/```([a-zA-Z0-9_+-]*)\n([\s\S]*?)\n```/m',
            function ($m) use (&$codeBlocks) {
                $lang = trim($m[1]);
                $code = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cls  = $lang ? ' class="lang-' . preg_replace('/[^a-z0-9-]/i', '', $lang) . '"' : '';
                $idx  = count($codeBlocks);
                $codeBlocks[$idx] = '<pre><code' . $cls . '>' . $code . '</code></pre>';
                return "\n\n@@CODEBLOCK_{$idx}@@\n\n";
            },
            $md
        );

        // 2. Izvleci shortcodes [cta:type] ali [cta:type id=42]
        $md = preg_replace_callback(
            '/\[cta:([a-z_-]+)(?:\s+([^\]]+))?\]/i',
            function ($m) {
                $type = strtolower($m[1]);
                $args = isset($m[2]) ? trim($m[2]) : '';
                $argEnc = $args !== '' ? '|' . htmlspecialchars($args, ENT_QUOTES, 'UTF-8') : '';
                return '@@CTA:' . $type . $argEnc . '@@';
            },
            $md
        );

        // 3. Razdeli na bloke po praznih vrsticah
        $blocks = preg_split('/\n{2,}/', $md);
        $html   = [];

        $listBuffer = [];
        $listType   = null; // 'ul' | 'ol'

        $flushList = function () use (&$listBuffer, &$listType, &$html) {
            if (!empty($listBuffer)) {
                $tag = $listType ?: 'ul';
                $items = array_map(function ($item) {
                    return '<li>' . $this->inline($item) . '</li>';
                }, $listBuffer);
                $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
                $listBuffer = [];
                $listType   = null;
            }
        };

        foreach ($blocks as $block) {
            $block = trim($block, "\n");
            if ($block === '') continue;

            // Code block placeholder
            if (preg_match('/^@@CODEBLOCK_(\d+)@@$/', $block, $m)) {
                $flushList();
                $html[] = $codeBlocks[(int)$m[1]];
                continue;
            }

            // Horizontalna linija
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $block)) {
                $flushList();
                $html[] = '<hr>';
                continue;
            }

            // Naslov
            if (preg_match('/^(#{1,6})\s+(.+?)\s*$/m', $block, $m) && substr($block, 0, 1) === '#') {
                $flushList();
                $level   = strlen($m[1]);
                $text    = $this->inline(trim($m[2]));
                $textRaw = strip_tags($text);
                $anchor  = $this->slugify($textRaw);
                $anchorEsc = htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8');
                // Pri h2/h3 dodamo hover anchor link, da se ujema z design system-om.
                $anchorLink = ($level === 2 || $level === 3)
                    ? ' <a href="#' . $anchorEsc . '" class="anchor" aria-label="Povezava na razdelek">#</a>'
                    : '';
                $html[]  = '<h' . $level . ' id="' . $anchorEsc . '">' . $text . $anchorLink . '</h' . $level . '>';
                continue;
            }

            // Citat
            if (preg_match('/^(?:>\s?.+(?:\n|$))+/m', $block) && substr($block, 0, 1) === '>') {
                $flushList();
                $lines = explode("\n", $block);
                $clean = [];
                foreach ($lines as $ln) {
                    $clean[] = preg_replace('/^>\s?/', '', $ln);
                }
                $inner = $this->inline(trim(implode("\n", $clean)));
                // Spremeni \n znotraj quote-a v <br>
                $inner = nl2br($inner, false);
                $html[] = '<blockquote>' . $inner . '</blockquote>';
                continue;
            }

            // Seznam (UL ali OL) – lahko združimo več zaporednih blokov
            if (preg_match('/^[\-\*\+]\s+/m', $block) && preg_match('/^[\-\*\+]\s+/', $block)) {
                if ($listType !== null && $listType !== 'ul') $flushList();
                $listType = 'ul';
                $items = preg_split('/\n(?=[\-\*\+]\s)/', $block);
                foreach ($items as $it) {
                    $listBuffer[] = preg_replace('/^[\-\*\+]\s+/', '', trim($it));
                }
                continue;
            }
            if (preg_match('/^\d+\.\s+/m', $block) && preg_match('/^\d+\.\s+/', $block)) {
                if ($listType !== null && $listType !== 'ol') $flushList();
                $listType = 'ol';
                $items = preg_split('/\n(?=\d+\.\s)/', $block);
                foreach ($items as $it) {
                    $listBuffer[] = preg_replace('/^\d+\.\s+/', '', trim($it));
                }
                continue;
            }

            // Drugo: navaden odstavek
            $flushList();
            // \n znotraj odstavka → presledek (ne <br>) razen če konča z 2 presledkoma
            $para = preg_replace('/  \n/', '<br>', $block);
            $para = str_replace("\n", ' ', $para);
            $html[] = '<p>' . $this->inline(trim($para)) . '</p>';
        }
        $flushList();

        return implode("\n", $html);
    }

    /** Inline pretvorba: bold, italic, code, link, image. */
    private function inline(string $text): string {
        // 1. Backslash escape: zaščiti \* \_ itd.
        $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!>])/', function ($m) {
            return '@@ESC:' . ord($m[1]) . '@@';
        }, $text);

        // 2. Inline code – izvleci, da jih ne diramo z drugimi pravili
        $codes = [];
        $text  = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
            $idx = count($codes);
            $codes[$idx] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            return "@@INLINECODE_{$idx}@@";
        }, $text);

        // 3. Slike: ![alt](src) ali ![alt](src "title") ali ![alt](media:ID)
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            function ($m) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $src = $m[2];
                $title = isset($m[3]) ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
                if (strpos($src, 'media:') === 0) {
                    // V helper-u zamenjamo s pravim <picture>
                    $cap = isset($m[3]) ? '|' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') : '';
                    return '@@MEDIAREF:' . substr($src, 6) . '|' . $alt . $cap . '@@';
                }
                $srcEsc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                return '<img src="' . $srcEsc . '" alt="' . $alt . '"' . $title . ' loading="lazy" decoding="async">';
            },
            $text
        );

        // 4. Linki: [text](url) ali [text](url "title")
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            function ($m) {
                $linkText = $m[1]; // znotraj se inline pravila ne aplicirajo, MD je tako
                $href = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $title = isset($m[3]) ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
                $rel = '';
                if (preg_match('#^https?://#i', $m[2])) {
                    $host = parse_url($m[2], PHP_URL_HOST);
                    if ($host && stripos($host, 'rezervacije.si') === false && stripos($host, 'novolon.com') === false) {
                        $rel = ' rel="noopener noreferrer" target="_blank"';
                    }
                }
                return '<a href="' . $href . '"' . $title . $rel . '>' . htmlspecialchars($linkText, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $text
        );

        // 5. Bold (**…** ali __…__)
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s',     '<strong>$1</strong>', $text);

        // 6. Italic (*…* ali _…_) – bolj previdno, da ne ujamemo sredine besede
        $text = preg_replace('/(?<![*\w])\*([^\*\n]+?)\*(?!\w)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![_\w])_([^_\n]+?)_(?!\w)/',     '<em>$1</em>', $text);

        // 7. Vrni inline code
        $text = preg_replace_callback('/@@INLINECODE_(\d+)@@/', function ($m) use ($codes) {
            return $codes[(int)$m[1]];
        }, $text);

        // 8. Vrni escaped znake
        $text = preg_replace_callback('/@@ESC:(\d+)@@/', function ($m) {
            return chr((int)$m[1]);
        }, $text);

        return $text;
    }

    /** URL-friendly slug iz teksta (za heading anchors). */
    private function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        // Translit najbolj pogostih znakov
        $map = [
            'č'=>'c','ć'=>'c','š'=>'s','ž'=>'z','đ'=>'d',
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','å'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ñ'=>'n','ý'=>'y','ÿ'=>'y',
            'ß'=>'ss','æ'=>'ae','œ'=>'oe',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}
