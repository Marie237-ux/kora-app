"""Copie vérifiable des références et matrice exhaustive issue du prompt, sans le modifier."""
from pathlib import Path
import hashlib
import re
import shutil

root = Path(__file__).resolve().parents[1]
refs = root / 'references'
refs.mkdir(exist_ok=True)
rows = []
for source in sorted((root / 'Reference').glob('*.png')):
    dest = refs / source.name
    if not dest.exists():
        shutil.copy2(source, dest)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    assert hashlib.sha256(dest.read_bytes()).hexdigest() == digest
    rows.append(f'| `{source.name}` | `{digest}` |')
mapping = '''
Inspection visuelle : 1.png = palette/direction sombre compacte ; 2.png = direction sombre et typographies ; 3.png = palette/direction claire compacte ; 4.png = direction claire et typographies ; Logo.png = logo original noir avec chat sur le a, fond blanc.

Les noms originaux fournis diffèrent de ceux du prompt. Cette correspondance est visuelle, pas une affirmation sur les noms d'origine. Les originaux Reference/ ne sont pas modifiés. Aucune photo produit n'est fournie dans ces PNG.

Absents : Briefing_detaille_Marketplace_Douala_MAJ(1).docx et image(20261028-230552).png (composition grand sac). Les quatre directions sont présentes sous noms numérotés ; le logo est présent sous Logo.png. L'ancienne mention du paiement à livraison dans les images est remplacée dans le produit par le paiement préalable du prompt.
'''
(refs / 'README.md').write_text('# Références inspectées\n\n' + mapping + '\n| Fichier | SHA-256 |\n| --- | --- |\n' + '\n'.join(rows) + '\n', encoding='utf-8')
prompt = (root / 'KORA_PROMPT_MAITRE_CODEX.md').read_text(encoding='utf-8')
headings = re.findall(r'^## (\d+)\. (.+)$', prompt, re.M)
scenarios = re.findall(r'^\| (V\d+) \| (.+?) \|$', prompt, re.M)
assert len(headings) == 38 and len(scenarios) == 110
target = root / 'docs' / 'EXIGENCES.md'
if not target.exists():
    content = '# Matrice intégrale des exigences\n\nSource : prompt maître, 29 octobre 2026, 38 sections et 110 scénarios. Aucun module web reporté. Seule l’application native client est hors livraison actuelle.\n\nÉtats : À réaliser / En cours / Vérifié partiellement / Validé (chemin complet prouvé). Une preuve unitaire ne valide pas un parcours complet.\n\n## Couverture par section\n\n| ID | Exigence (section du prompt) | Écrans/API/preuve | État |\n| --- | --- | --- | --- |\n'
    for number, title in headings:
        content += f'| R{int(number):02d} | §{number} {title} | À relier à l’implémentation | À réaliser |\n'
    content += '\n## Scénarios obligatoires avant lancement\n\n| ID | Scénario exact | Implémentation et preuve | État |\n| --- | --- | --- | --- |\n'
    for code, description in scenarios:
        content += f'| {code} | {description} | Non exécuté | À réaliser |\n'
    target.write_text(content, encoding='utf-8')
print(f'{len(rows)} références copiées et vérifiées ; {len(headings)} sections ; {len(scenarios)} scénarios conservés.')
