<?php
/**
 * Stop hook — PROJECT_LOCK م-1: every reply to the owner is in Arabic.
 * Two full replies were written in English in this project despite the written rule (and three in the owner's
 * previous system). This reads the last assistant text of the turn; if its prose (code, paths and links removed)
 * is mostly Latin letters, it blocks the stop and asks for the reply to be rewritten in Arabic.
 */

$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
if (!empty($input['stop_hook_active'])) {
    exit(0); // already rewriting after a block: never loop
}

$text = (string) ($input['last_assistant_message'] ?? '');
$transcript = (string) ($input['transcript_path'] ?? '');
if ($text === '' && $transcript !== '' && is_file($transcript)) {
    $lines = file($transcript, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $row = json_decode($lines[$i], true);
        if (($row['type'] ?? '') === 'user' && is_string($row['message']['content'] ?? null)) {
            break; // the turn started here
        }
        if (($row['type'] ?? '') !== 'assistant') {
            continue;
        }
        foreach (array_reverse((array) ($row['message']['content'] ?? [])) as $part) {
            if (($part['type'] ?? '') === 'text' && trim((string) $part['text']) !== '') {
                $text = (string) $part['text'];
                break 2;
            }
        }
    }
}
if ($text === '') {
    exit(0);
}

// Judge the prose only: code blocks, inline code, links, paths and identifiers are legitimately Latin.
$prose = preg_replace(['/```.*?```/s', '/`[^`]*`/', '/\[[^\]]*\]\([^)]*\)/', '#https?://\S+#', '#\S*[/\\\\]\S*#', '/\b[A-Za-z]+[-_.:][A-Za-z0-9_.:-]+\b/', '/\b(REQ|Q|M|P)-?\d+\b/'], ' ', $text);
$arabic = preg_match_all('/\p{Arabic}/u', (string) $prose);
$latin = preg_match_all('/[A-Za-z]/', (string) $prose);

if ($latin >= 60 && $arabic < $latin) {
    echo json_encode(['decision' => 'block', 'reason' => 'ردّك الأخير مكتوب بالإنجليزية. القاعدة م-1 في PROJECT_LOCK.md: كل رد للمالك بالعربية بلا استثناء (الكود والأوامر وحدها بالإنجليزية). أعد كتابة الرد نفسه كاملاً بالعربية الآن، دون اعتذار طويل.'], JSON_UNESCAPED_UNICODE);
}
exit(0);
