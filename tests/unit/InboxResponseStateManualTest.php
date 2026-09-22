<?php
// Regression check untuk Inbox::attachResponseState() -- memuat CLASS
// ASLI dari app/Controllers/Inbox.php via Reflection (bukan salinan
// logika), dengan stub minimal utk parent class CodeIgniter\Controller
// supaya bisa jalan tanpa bootstrap framework/DB penuh (`php
// tests/unit/InboxResponseStateManualTest.php`).

namespace CodeIgniter {
    if (!class_exists(Controller::class)) {
        class Controller {}
    }
}

namespace {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        require __DIR__ . '/../../app/Controllers/BaseController.php';
    require __DIR__ . '/../../app/Controllers/Inbox.php';

    $ref = new ReflectionClass(\App\Controllers\Inbox::class);
    $inbox = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('attachResponseState');
    $method->setAccessible(true);

    function nowJakarta(string $modify = 'now'): string {
        return (new DateTime($modify, new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }

    $pass = 0; $fail = 0;
    function check(string $name, $expected, $actual, &$pass, &$fail): void {
        if ($expected === $actual) {
            echo "[PASS] $name => $actual\n";
            $pass++;
        } else {
            echo "[FAIL] $name => expected=$expected actual=$actual\n";
            $fail++;
        }
    }

    // ---------------------------------------------------------------
    // Skenario 1: open, incoming terbaru, belum ditandai dibaca
    // ---------------------------------------------------------------
    $conv = [
        'status' => 'open',
        'last_message_direction' => 'incoming',
        'last_message_at' => nowJakarta('-5 minutes'),
        'last_seen_by_assignee_at' => null,
        'snoozed_until' => null,
    ];
    $result = $method->invoke($inbox, [$conv]);
    check('Skenario 1: incoming belum dibaca', 'perlu_dibalas', $result[0]['response_state'], $pass, $fail);

    // ---------------------------------------------------------------
    // Skenario 2: tandaiDibaca() dipanggil (last_seen diset now),
    // TANPA membalas (direction tetap incoming) -> harus jadi
    // menunggu_customer (ini bukti fix bug lama).
    // ---------------------------------------------------------------
    $lastMessageAt = nowJakarta('-5 minutes');
    $conv2 = [
        'status' => 'open',
        'last_message_direction' => 'incoming', // belum membalas
        'last_message_at' => $lastMessageAt,
        'last_seen_by_assignee_at' => nowJakarta(), // simulasi tandaiDibaca()
        'snoozed_until' => null,
    ];
    $result2 = $method->invoke($inbox, [$conv2]);
    check('Skenario 2: tandaiDibaca tanpa balas', 'menunggu_customer', $result2[0]['response_state'], $pass, $fail);

    // ---------------------------------------------------------------
    // Skenario 3a: snooze 60 menit -> follow_up
    // ---------------------------------------------------------------
    $conv3 = [
        'status' => 'open',
        'last_message_direction' => 'incoming',
        'last_message_at' => nowJakarta('-10 minutes'),
        'last_seen_by_assignee_at' => nowJakarta('-9 minutes'),
        'snoozed_until' => nowJakarta('+60 minutes'), // simulasi snoozePercakapan(60)
    ];
    $result3a = $method->invoke($inbox, [$conv3]);
    check('Skenario 3a: setelah snooze 60 menit', 'follow_up', $result3a[0]['response_state'], $pass, $fail);

    // ---------------------------------------------------------------
    // Skenario 3b: simulasi incoming baru masuk -> InboxGatewayApi::messages()
    // mereset snoozed_until = null (baris yang ditambah di Langkah 4),
    // lalu recompute response_state.
    // ---------------------------------------------------------------
    $conv3b = $conv3;
    $newIncomingAt = nowJakarta(); // pesan baru masuk sekarang
    $conv3b['last_message_at'] = $newIncomingAt;
    $conv3b['last_message_direction'] = 'incoming';
    $conv3b['snoozed_until'] = null; // <-- efek dari fix Langkah 4
    $result3b = $method->invoke($inbox, [$conv3b]);
    check('Skenario 3b: snoozed_until direset ke NULL', null, $conv3b['snoozed_until'], $pass, $fail);
    check('Skenario 3b: response_state balik perlu_dibalas', 'perlu_dibalas', $result3b[0]['response_state'], $pass, $fail);

    // ---------------------------------------------------------------
    // Skenario 4: kasir balas via kirimMedia() (gambar/dokumen, bukan
    // teks) -- conversationUpdate hasil kirimMedia() menulis
    // last_message_direction=outgoing DAN last_seen_by_assignee_at=$now
    // (sama seperti kirimKeConversation()). Regression check untuk bug
    // yang sebelumnya lolos: kirimMedia() punya $conversationUpdate
    // sendiri yang lupa menyertakan last_seen_by_assignee_at, sehingga
    // conversation tetap perlu_dibalas walau customer sudah dibalas.
    // ---------------------------------------------------------------
    $now4 = nowJakarta();
    $conv4 = [
        'status' => 'open',
        'last_message_direction' => 'outgoing', // hasil kirimMedia()
        'last_message_at' => $now4,
        'last_seen_by_assignee_at' => $now4, // hasil kirimMedia() (fix)
        'snoozed_until' => null,
    ];
    $result4 = $method->invoke($inbox, [$conv4]);
    check('Skenario 4: balas via kirimMedia', 'menunggu_customer', $result4[0]['response_state'], $pass, $fail);

    echo "\n== $pass PASS, $fail FAIL ==\n";
        exit($fail > 0 ? 1 : 0);
    }
}
