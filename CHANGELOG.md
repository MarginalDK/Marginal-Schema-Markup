# Ændringslog

## 1.0.1

### Sikkerhed
- Kun administratorer kan nu se og ændre JSON-LD-feltet på sider (tidligere også redaktører). Feltet kan heller ikke længere læses eller ændres via XML-RPC.
- En privat side eller kladde, der er valgt som "Indlægsside", viser ikke længere sin JSON-LD for besøgende.

### Rettelser
- Når en side gemmes, kan dens JSON-LD ikke længere havne på en anden side (fx med oversættelses- eller synkroniseringsplugins).
- Ingen fatal fejl, hvis pluginnet ved en fejl er installeret to gange – og dataene bevares, når dubletten slettes.
- Loggen viser ikke længere falske fejl fra oppetidsovervågning, og den opdager nu også, når et tema eller plugin fjerner JSON-LD'en fra siden.
- Valideringen siger kun "gyldig", hvis JSON-LD'en også kan udskrives. JSON-LD over 200 KB afvises med en tydelig besked i stedet for at blive klippet over.
- Opdateringstjek mod GitHub kan ikke længere få administrationen til at hænge, og lange release-noter med æ, ø og å ødelægger ikke længere opdateringscachen.

## 1.0.0

- Første version: global og side-specifik JSON-LD, fejl-log og automatiske opdateringer fra GitHub.
