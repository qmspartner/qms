<?php
require_once __DIR__ . '/db.php';

/**
 * Sender en e-mail til den specifikt valgte godkender om, at en dokumentversion
 * venter på netop deres godkendelse.
 *
 * @param array $approver Skal indeholde 'name' og 'email'.
 */
function notify_assigned_approver(array $approver, int $documentId, string $docTitle, string $docNumber, string $versionNumber, string $uploadedByName): void {
    $link = rtrim(SITE_URL, '/') . '/document.php?id=' . $documentId;
    $subject = 'Godkendelse påkrævet: ' . $docTitle . ' (' . $docNumber . ')';

    $body = "Hej " . $approver['name'] . ",\n\n"
        . $uploadedByName . " har uploadet version " . $versionNumber . " af dokumentet \"" . $docTitle . "\" (" . $docNumber . "), som nu venter på din godkendelse.\n\n"
        . "Åbn dokumentet her:\n" . $link . "\n\n"
        . "Denne besked er sendt automatisk af QMS Dokumentstyring.";

    $fromName = 'QMS Dokumentstyring';
    $headers = "From: " . $fromName . " <" . MAIL_FROM . ">\r\n"
        . "Reply-To: " . MAIL_FROM . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    // -f sætter den TEKNISKE afsenderadresse (envelope-from) til at matche den SYNLIGE
    // afsenderadresse (From-headeren) ovenfor. Uden den vælger serveren selv en teknisk
    // afsenderadresse, som ofte ikke matcher - og det udløser typisk spamfiltre.
    $envelopeFrom = '-f' . MAIL_FROM;

    // mail() kan fejle (fx pga. serverens opsætning) - det må aldrig stoppe selve uploadet,
    // så vi ignorerer bevidst returværdien og lader upload-flowet fortsætte uanset hvad.
    @mail($approver['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, $envelopeFrom);
}

/**
 * Sender en e-mail med et link til at nulstille adgangskoden.
 *
 * @param array $recipient Skal indeholde 'name' og 'email'.
 */
function notify_password_reset(array $recipient, string $resetLink): void {
    $subject = 'Nulstil din adgangskode';

    $body = "Hej " . $recipient['name'] . ",\n\n"
        . "Du (eller nogen med adgang til din e-mail) har anmodet om at nulstille adgangskoden til QMS Dokumentstyring.\n\n"
        . "Klik på linket herunder for at vælge en ny adgangskode. Linket er gyldigt i 1 time:\n" . $resetLink . "\n\n"
        . "Hvis du ikke har anmodet om dette, kan du roligt ignorere denne e-mail - din adgangskode forbliver uændret.\n\n"
        . "Denne besked er sendt automatisk af QMS Dokumentstyring.";

    $fromName = 'QMS Dokumentstyring';
    $headers = "From: " . $fromName . " <" . MAIL_FROM . ">\r\n"
        . "Reply-To: " . MAIL_FROM . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $envelopeFrom = '-f' . MAIL_FROM;

    @mail($recipient['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, $envelopeFrom);
}

/**
 * Sender en e-mail til en bred modtagerkreds (fx alle Redaktører/Administratorer) om,
 * at en ny baseline af et konfigurationsstyret dokument er godkendt og trådt i kraft.
 *
 * @param array $recipient Skal indeholde 'name' og 'email'.
 */
function notify_configuration_change(array $recipient, int $documentId, string $docTitle, string $docNumber, string $versionNumber, string $approvedByName, string $changeDescription): void {
    $link = rtrim(SITE_URL, '/') . '/document.php?id=' . $documentId;
    $subject = 'Ny baseline godkendt: ' . $docTitle . ' (' . $docNumber . ')';

    $body = "Hej " . $recipient['name'] . ",\n\n"
        . "En ny version (" . $versionNumber . ") af det konfigurationsstyrede dokument \"" . $docTitle . "\" (" . $docNumber . ") er netop godkendt af " . $approvedByName . ".\n\n"
        . ($changeDescription ? "Begrundelse for ændringen:\n" . $changeDescription . "\n\n" : "")
        . "Åbn dokumentet her:\n" . $link . "\n\n"
        . "Denne besked er sendt automatisk af QMS Dokumentstyring, fordi du har rollen Administrator eller Redaktør.";

    $fromName = 'QMS Dokumentstyring';
    $headers = "From: " . $fromName . " <" . MAIL_FROM . ">\r\n"
        . "Reply-To: " . MAIL_FROM . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $envelopeFrom = '-f' . MAIL_FROM;

    @mail($recipient['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, $envelopeFrom);
}
