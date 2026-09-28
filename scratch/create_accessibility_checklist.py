"""Gera o checklist original usado para exercitar arquivos PDF no GovDoc."""

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "output" / "pdf" / "checklist-publicacao-acessivel.pdf"
OUTPUT.parent.mkdir(parents=True, exist_ok=True)

pdfmetrics.registerFont(TTFont("ArialDocGov", "C:/Windows/Fonts/arial.ttf"))
pdfmetrics.registerFont(TTFont("ArialDocGovBold", "C:/Windows/Fonts/arialbd.ttf"))

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name="DocTitle", fontName="ArialDocGovBold", fontSize=18, leading=22, textColor=colors.HexColor("#172635"), spaceAfter=12))
styles.add(ParagraphStyle(name="DocSub", fontName="ArialDocGov", fontSize=9, leading=14, textColor=colors.HexColor("#536270"), spaceAfter=16))
styles.add(ParagraphStyle(name="DocBody", fontName="ArialDocGov", fontSize=10, leading=15, textColor=colors.HexColor("#172635")))
styles.add(ParagraphStyle(name="DocSmall", fontName="ArialDocGov", fontSize=8, leading=12, textColor=colors.HexColor("#536270")))

doc = SimpleDocTemplate(str(OUTPUT), pagesize=A4, rightMargin=20 * mm, leftMargin=20 * mm, topMargin=19 * mm, bottomMargin=18 * mm, title="Checklist de publicação acessível", author="GovDoc")
story = [
    Paragraph("Checklist de publicação acessível", styles["DocTitle"]),
    Paragraph("Roteiro de apoio para revisar um conteúdo digital antes de publicá-lo. Não substitui a avaliação de acessibilidade da página ou documento final.", styles["DocSub"]),
]

checks = [
    ("1", "Título e estrutura", "O título descreve o conteúdo e os subtítulos seguem uma ordem lógica."),
    ("2", "Linguagem", "As frases são diretas; siglas e termos técnicos são explicados na primeira ocorrência."),
    ("3", "Imagens", "Imagens informativas têm texto alternativo útil; imagens decorativas não repetem informação."),
    ("4", "Links", "O texto de cada link explica seu destino fora do contexto da frase."),
    ("5", "Tabelas e arquivos", "Tabelas possuem cabeçalhos claros; arquivos são pesquisáveis e mantêm ordem de leitura."),
    ("6", "Revisão", "O conteúdo foi conferido com teclado e, quando possível, com tecnologia assistiva."),
]

rows = []
for number, title, detail in checks:
    rows.append([Paragraph(number, styles["DocBody"]), Paragraph(f"<b>{title}</b><br/>{detail}", styles["DocBody"]), "[  ]"])

table = Table(rows, colWidths=[12 * mm, 142 * mm, 13 * mm], hAlign="LEFT")
table.setStyle(TableStyle([
    ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ("LINEBELOW", (0, 0), (-1, -2), 0.35, colors.HexColor("#DCE4E8")),
    ("TOPPADDING", (0, 0), (-1, -1), 9),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 9),
    ("LEFTPADDING", (0, 0), (-1, -1), 3),
    ("RIGHTPADDING", (0, 0), (-1, -1), 3),
]))
story.extend([table, Spacer(1, 13 * mm)])
story.append(Paragraph("Fontes de referência", styles["DocBody"]))
story.append(Spacer(1, 3 * mm))
story.append(Paragraph("Governo Digital: Modelo de Acessibilidade em Governo Eletrônico (eMAG) — https://www.gov.br/governodigital/pt-br/acessibilidade-e-usuario/acessibilidade-digital/modelo-de-acessibilidade", styles["DocSmall"]))
story.append(Spacer(1, 2 * mm))
story.append(Paragraph("ENAP: Checklist – Conteúdos e Materiais Digitais Acessíveis — https://enap.gov.br/docs/217/Checklist_Rota_2__Conteudos_e_Materiais_Digitais_Acessiveis.pdf", styles["DocSmall"]))
story.append(Spacer(1, 7 * mm))
story.append(Paragraph("Material de apoio geral • versão 1.0 • setembro de 2026", styles["DocSmall"]))

doc.build(story)
print(OUTPUT)
