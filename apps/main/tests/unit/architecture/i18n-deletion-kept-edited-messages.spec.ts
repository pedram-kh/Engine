/**
 * AH-119 Stage B (Q13) — the in-app "What stays" text tells the creator that the earlier text of
 * edited messages and the text of deleted ones is kept hidden until the account is erased. The
 * sentence is pinned per locale (es by hand), so a locale cannot lose it or fall back to English.
 * A notice, not legally reviewed; the policy section in `docs/store/` says the same.
 */

import { describe, expect, it } from 'vitest'

const files = import.meta.glob('@/core/i18n/locales/*/creator.json', {
  eager: true,
  import: 'default',
}) as Record<string, { creator: { ui: { security: { delete: { kept: string } } } } }>

const SENTENCE: Record<string, string> = {
  bg: 'Предишният текст на редактирани от вас съобщения и текстът на изтритите съобщения се пазят скрити, докато акаунтът ви не бъде изтрит.',
  cs: 'Původní znění zpráv, které jste upravili, a text zpráv, které jste smazali, zůstávají uloženy skryté, dokud nebude váš účet vymazán.',
  da: 'Den tidligere tekst i beskeder, du har redigeret, og teksten i beskeder, du har slettet, gemmes skjult, indtil din konto slettes.',
  de: 'Der frühere Wortlaut bearbeiteter Nachrichten und der Text gelöschter Nachrichten bleiben verborgen gespeichert, bis dein Konto gelöscht wird.',
  el: 'Το προηγούμενο κείμενο των μηνυμάτων που επεξεργαστήκατε και το κείμενο των μηνυμάτων που διαγράψατε διατηρούνται κρυφά μέχρι να διαγραφεί ο λογαριασμός σας.',
  en: 'The earlier text of messages you edited, and the text of messages you deleted, is kept hidden from view until your account is erased.',
  es: 'El texto anterior de los mensajes que editaste y el texto de los que eliminaste se conservan ocultos hasta que se borre tu cuenta.',
  et: 'Muudetud sõnumite varasem tekst ja kustutatud sõnumite tekst säilitatakse peidetuna, kuni sinu konto kustutatakse.',
  fi: 'Muokkaamiesi viestien aiempi teksti ja poistamiesi viestien teksti säilytetään piilotettuna, kunnes tilisi poistetaan.',
  fr: "L'ancien texte des messages que vous avez modifiés et le texte de ceux que vous avez supprimés sont conservés, masqués, jusqu'à l'effacement de votre compte.",
  ga: 'Coinnítear téacs roimhe seo na dteachtaireachtaí a chuir tú in eagar, agus téacs na dteachtaireachtaí a scrios tú, i bhfolach go dtí go scriosfar do chuntas.',
  hr: 'Prethodni tekst poruka koje ste uredili i tekst poruka koje ste izbrisali čuvaju se skriveni dok se vaš račun ne izbriše.',
  hu: 'A szerkesztett üzeneteid korábbi szövegét és a törölt üzeneteid szövegét rejtve őrizzük, amíg a fiókod törlésre nem kerül.',
  it: 'Il testo precedente dei messaggi che hai modificato e il testo di quelli che hai eliminato vengono conservati nascosti fino alla cancellazione del tuo account.',
  lt: 'Redaguotų žinučių ankstesnis tekstas ir ištrintų žinučių tekstas saugomi paslėpti, kol jūsų paskyra bus ištrinta.',
  lv: 'Rediģēto ziņojumu iepriekšējais teksts un dzēsto ziņojumu teksts tiek glabāts apslēpts, līdz jūsu konts tiks dzēsts.',
  mt: 'It-test preċedenti tal-messaġġi li editjajt u t-test tal-messaġġi li ħassart jinżammu moħbija sakemm il-kont tiegħek jitħassar.',
  nl: 'De eerdere tekst van berichten die je hebt bewerkt en de tekst van berichten die je hebt verwijderd blijven verborgen bewaard tot je account wordt gewist.',
  pl: 'Wcześniejsza treść wiadomości, które edytowałeś, oraz treść wiadomości, które usunąłeś, są przechowywane w ukryciu do czasu usunięcia Twojego konta.',
  pt: 'O texto anterior das mensagens que editou e o texto das que eliminou são mantidos ocultos até a sua conta ser apagada.',
  ro: 'Textul anterior al mesajelor pe care le-ai editat și textul mesajelor pe care le-ai șters sunt păstrate ascunse până la ștergerea contului tău.',
  sk: 'Pôvodné znenie správ, ktoré ste upravili, a text správ, ktoré ste odstránili, sa uchovávajú skryté, kým sa váš účet nevymaže.',
  sl: 'Prejšnje besedilo sporočil, ki ste jih uredili, in besedilo sporočil, ki ste jih izbrisali, se hrani skrito, dokler vaš račun ni izbrisan.',
  sv: 'Den tidigare texten i meddelanden du har redigerat och texten i meddelanden du har raderat sparas dolda tills ditt konto raderas.',
}

function kept(locale: string): string {
  const entry = Object.entries(files).find(([path]) => path.includes(`/locales/${locale}/`))
  if (entry === undefined) {
    throw new Error(`no creator.json for ${locale}`)
  }
  return entry[1].creator.ui.security.delete.kept
}

describe('"What stays" states that edited originals and deleted messages are kept hidden', () => {
  it('pins a sentence for exactly the 24 locales', () => {
    expect(Object.keys(SENTENCE)).toHaveLength(24)
    expect(Object.keys(files)).toHaveLength(24)
  })

  for (const [locale, sentence] of Object.entries(SENTENCE)) {
    it(`${locale} ends the text with its sentence, after the original wording`, () => {
      const text = kept(locale)
      expect(text.endsWith(` ${sentence}`)).toBe(true)
      expect(text.length).toBeGreaterThan(sentence.length + 40)
    })
  }

  it('no locale other than en reuses the English sentence', () => {
    for (const [locale, sentence] of Object.entries(SENTENCE)) {
      if (locale !== 'en') {
        expect(sentence).not.toBe(SENTENCE.en)
      }
    }
  })
})
