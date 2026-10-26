=== Lipovenesc Evenimente ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv2 or later

Evenimente în curte cu înscriere online, bilete și plată prin WooCommerce.

== Ce face ==

* Categorii cu reguli proprii (Ateliere, Gastronomie, Concerte și seri în curte, sau oricare altele): minim și maxim participanți, preț adult/copil, mod de rezervare, culoare, imagine, descriere. Evenimentele moștenesc regulile și le pot suprascrie.
* Două moduri: „Rezervă” (locul se ține, plata la fermă sau gratuit) și „Cumpără bilet” (plata online prin WooCommerce, cu Stripe sau orice alt procesator instalat). Pluginul nu atinge datele de card.
* Minim participanți: „se confirmă la X înscriși” pe card; alertă pe email cu N ore înainte dacă minimul nu e atins; anularea evenimentului cu un click trimite emailuri și rambursează plățile online.
* Bilete cu cod QR și cod scurt, pagină de bilet pe telefon (printabilă), fișier .ics, link de anulare.
* Check-in: scanarea QR-ului cu telefonul (logat în site) bifează prezența; listă de check-in în admin, cu căutare după nume sau cod.
* Listă de așteptare cu promovare automată; la evenimentele cu bilet, cel promovat primește 24 h și un link de plată.
* Locuri ținute până la plată (implicit 30 de minute), eliberate automat dacă plata nu se face.
* Pagină proprie pentru fiecare eveniment (/eveniment/nume/), cu date structurate Event (JSON-LD) și butoane de distribuire.
* Shortcode-uri: [lipovenesc_categorii], [lipovenesc_evenimente], [lipovenesc_evenimente categorie="ateliere"], [lipovenesc_evenimente layout="urmatoarele" limit="3"], [lipovenesc_evenimente layout="arhiva"], [lipovenesc_eveniment id="123"].
* Emailuri: confirmare cu bilet, plată în așteptare, listă de așteptare, promovare, anulare, eveniment anulat, reamintire cu o zi înainte, alertă sub minim.
* Lista participanților printabilă, export CSV, înscrieri adăugate manual (telefonic).

== Instalare / actualizare ==

1. Pluginuri → Adaugă nou → Încarcă plugin → alegeți arhiva → „Înlocuiește versiunea curentă cu cea încărcată”.
2. Evenimente → Setări: emailurile de notificare, telefonul, culorile, pagina cu lista.
3. Evenimente → Categorii: verificați cele 3 categorii create automat (reguli, imagini).
4. Puneți [lipovenesc_categorii] și [lipovenesc_evenimente] pe pagina Evenimente în curte.

== Changelog ==

= 2.4.1 =
* Date structurate Event: câmp nou „Cine susține” (performer); fără el se folosește Ferma Lipovenesc.
* Descrierea din datele structurate nu mai rămâne goală: rezumat, conținut, descrierea Yoast sau o frază generată din titlu, categorie și dată.
* Numele evenimentului apare fără entități HTML (&#8211;).

= 2.3.2 =
* „Verifică update” afișează un mesaj: versiune nouă disponibilă, ultima versiune sau eroare de conexiune.

= 2.3.1 =
* Verificarea de update citește versiunea direct de pe ramura main din GitHub (nu mai e nevoie de release sau tag).

= 2.3.0 =
* Actualizări direct din GitHub (github.com/mariansterea/lipovenesc-ateliere): update-ul apare în Module, cu link „Verifică update”.

= 2.2.3 =
* Acordul GDPR se bifează la pasul 2, lângă datele de contact; pasul 3 rămâne doar verificare + Rezervă.
* Câmpurile formularului nu mai ies din cadru pe telefoanele înguste.
* Rezumat: telefonul și emailul pe rânduri separate.

= 2.2.2 =
* Formular: mesajul de eroare apare în pasul curent, deasupra butoanelor; acordul GDPR nebifat se evidențiază cu roșu.
* Rezumatul de la pasul 3 nu mai iese din cadru pe telefon (etichetă deasupra valorii, emailurile lungi se rup).
* Titlurile pașilor nu mai preiau culoarea temei.
* Numele, telefonul, emailul și numărul de persoane se evidențiază cu roșu când lipsesc sau sunt greșite; marcajul dispare la corectare.
* Totalul nu se mai afișează cât timp nu e aleasă nicio persoană.

= 2.2.1 =
* Pe telefon și tabletă, fiecare card din listă are o miniatură pătrată în dreapta titlului.
* Prețul stă sub titlu în listă, ca titlurile lungi să nu se rupă.

= 2.2.0 =
* Lista de evenimente compactă, ca un calendar: titlu, preț, oră, locuri libere și vârstă pe card; data completă, descrierea, minimul de participanți și bara de locuri intră în „Detalii”.
* Pe telefon și tabletă, cardurile din listă nu mai afișează poza (rămâne pe pagina evenimentului); butonul stă pe același rând cu „Detalii”.

= 2.1.3 =
* Cardurile de categorie duc direct la lista filtrată (ancoră #lat-lista).

= 2.1.2 =
* Cardul nu mai repetă descrierea: rezumatul apare doar din câmpul Excerpt, descrierea stă sub „Detalii”.
* Font de text propriu (nu se mai moștenește din temă); pașii formularului fără numerotarea temei.

= 2.1.1 =
* Corectat: descrierea din editor nu se salva (formularul „Duplică la alte date” rupea formularul de editare). Duplicarea se face acum din același formular, la Actualizează.

= 2.1.0 =
* Evenimentele sunt implicit cu plată; „Gratuit” se bifează explicit (pe categorie sau pe eveniment).
* Eveniment cu plată fără preț: avertisment în admin, „Preț în curând” pe site, fără ofertă 0 lei în datele structurate.
* Categoriile existente trec automat pe „Cumpără bilet” la actualizare.
* Pagina de eveniment: detaliile ocupă coloana lată; pozele în <picture> (WebP) umplu cadrul.

= 2.0.1 =
* Pagina de eveniment: titlul nu mai apare de două ori (tema îl afișează deja).

= 2.0.1 =
* Categorii cu reguli, minim participanți, mod Rezervă / Cumpără bilet.
* Integrare WooCommerce (comandă creată din formular, plată pe pagina WooCommerce, rambursare la anulare).
* Bilete cu QR, check-in, .ics, pagini de eveniment, schema Event.
* Formular în 3 pași, design nou (crem / verde), shortcode-uri pentru embed.
* Duplicare la mai multe date, anularea unui eveniment cu un click.

= 1.0.0 =
* Prima versiune: calendar de ateliere cu înscriere.
