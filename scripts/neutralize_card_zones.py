#!/usr/bin/env python3
"""Prepare les cartes Walking Deck et construit leur sprite sheet.

Pour les cartes RURAL/URBAN autres que 00, le script conserve l'illustration
centrale et remplit :
- l'interieur de l'en-tete en blanc ;
- les consequences en noir, blanc ou gris selon leur fond dominant.

Les cartes PROTA ont un nettoyage dedie qui conserve leurs symboles et leurs
elements graphiques. Les cartes 00 et DEFAULT sont copiees sans modification;
les autres PNG sont ignores. Une sprite sheet RGBA est ensuite produite avec
des gouttieres transparentes et une taille maximale configurable.
La resolution est reduite automatiquement si necessaire pour respecter la
taille de fichier maximale demandee. Les cartes intermediaires restent en
memoire : seule la sprite sheet est ecrite dans le dossier cible.
"""

from __future__ import annotations

import argparse
from collections.abc import Callable
import math
from pathlib import Path
import re
import sys

from PIL import Image


WHITE = (255, 255, 255, 255)
BLACK = (33, 33, 33, 255)
GREY = (180, 180, 181, 255)
REGULAR_CARD_RE = re.compile(r"^(RURAL|URBAN)-(\d+)\.png$", re.IGNORECASE)
PROTA_RE = re.compile(r"^PROTA-(\d+)\.png$", re.IGNORECASE)


def luminance(pixel: tuple[int, ...]) -> float:
    return (pixel[0] + pixel[1] + pixel[2]) / 3


def row_fractions(image: Image.Image, y: int) -> tuple[float, float]:
    """Retourne les proportions noire et blanche au centre de la ligne."""
    width, _ = image.size
    left, right = round(width * 0.08), round(width * 0.92)
    pixels = image.load()
    black = white = 0
    total = max(1, right - left)
    for x in range(left, right):
        value = luminance(pixels[x, y])
        black += value < 55
        white += value > 225
    return black / total, white / total


def vertical_black_runs(image: Image.Image) -> list[tuple[int, int]]:
    """Retourne les traits verticaux persistants de l'en-tete."""
    width, height = image.size
    top, bottom = round(height * 0.045), round(height * 0.14)
    pixels = image.load()
    columns: list[int] = []
    for x in range(round(width * 0.03), round(width * 0.97)):
        fraction = sum(luminance(pixels[x, y]) < 65 for y in range(top, bottom)) / (bottom - top)
        if fraction > 0.62:
            columns.append(x)

    runs: list[tuple[int, int]] = []
    if not columns:
        return runs
    start = previous = columns[0]
    for x in columns[1:]:
        if x > previous + 1:
            runs.append((start, previous))
            start = x
        previous = x
    runs.append((start, previous))
    return runs


def name_fill_bounds(image: Image.Image) -> tuple[int, int]:
    """Isole la cellule du nom sans toucher aux cellules de symboles."""
    width, _ = image.size
    minimum_run_width = max(5, round(width * 0.011))
    strong_runs = [run for run in vertical_black_runs(image) if run[1] - run[0] + 1 >= minimum_run_width]

    left_separators = [
        run for run in strong_runs if width * 0.17 <= run[0] and run[1] <= width * 0.27
    ]
    right_separators = [
        run for run in strong_runs if width * 0.73 <= run[0] and run[1] <= width * 0.85
    ]

    # Une cellule laterale possede deux traits proches : le bord de la cellule
    # du symbole et le bord de la cellule du nom, separes par une petite marge.
    has_left_symbol = len(left_separators) >= 2
    has_right_symbol = len(right_separators) >= 2
    left = max(left_separators[-1][1] + 1, round(width * 0.24)) if has_left_symbol else round(width * 0.071)
    right = min(right_separators[0][0], round(width * 0.76)) if has_right_symbol else round(width * 0.923)
    return left, right


def find_consequences_top(image: Image.Image) -> int:
    """Trouve l'espace clair qui suit le bord inferieur de l'illustration."""
    _, height = image.size
    start = round(height * 0.54)
    stop = round(height * 0.78)
    metrics = [row_fractions(image, y) for y in range(start, stop)]

    # Une bordure d'illustration est une ligne largement noire, suivie d'un
    # espace clair stable. On choisit la premiere occurrence dans la partie
    # basse afin d'ignorer les aplats de l'illustration elle-meme.
    scale = height / 895
    lookahead = max(18, round(34 * scale))
    required_white_rows = max(6, round(9 * scale))
    for offset, (black_fraction, _) in enumerate(metrics):
        if black_fraction < 0.70:
            continue
        after = metrics[offset + 1 : offset + 1 + lookahead]
        if sum(white_fraction > 0.68 for _, white_fraction in after) >= required_white_rows:
            y = start + offset + 1
            while y < stop and row_fractions(image, y)[0] > 0.55:
                y += 1
            # Si un panneau cadre commence juste apres l'espace separateur,
            # l'espace lui-meme ne fait pas partie de la consequence.
            nearby_stop = min(stop, y + max(16, round(32 * scale)))
            for panel_y in range(y, nearby_stop):
                if row_fractions(image, panel_y)[0] > 0.82:
                    return panel_y
            return y

    # Repli prudent pour un gabarit tres atypique : la consequence commence
    # normalement vers les deux tiers de la carte.
    return round(height * 0.62)


def background_kind(pixel: tuple[int, ...]) -> str | None:
    """Reconnait uniquement les couleurs d'aplat, pas les traits des bordures."""
    red, green, blue = pixel[:3]
    if max(abs(red - 33), abs(green - 33), abs(blue - 33)) <= 7:
        return "black"
    if min(red, green, blue) >= 248:
        return "white"
    if max(abs(red - 180), abs(green - 180), abs(blue - 181)) <= 8:
        return "grey"
    if max(abs(red - 243), abs(green - 240), abs(blue - 245)) <= 8:
        return "offwhite"
    return None


def row_background_profile(
    image: Image.Image,
    y: int,
    left: int,
    right: int,
    allowed_kinds: tuple[str, ...] = ("black", "white", "grey", "offwhite"),
) -> tuple[dict[str, list[int]], bool]:
    """Mesure les aplats et signale les lignes formees par une bordure sombre."""
    positions: dict[str, list[int]] = {kind: [] for kind in allowed_kinds}
    pixels = image.load()
    very_dark = 0
    for x in range(left, right):
        pixel = pixels[x, y]
        kind = background_kind(pixel)
        if kind in positions:
            positions[kind].append(x)
        red, green, blue = pixel[:3]
        very_dark += (red + green + blue) / 3 < 27
    is_border = very_dark >= (right - left) * 0.50
    return positions, is_border


def fill_plan(
    image: Image.Image,
    top: int,
    bottom: int,
    left: int,
    right: int,
    allowed_kinds: tuple[str, ...],
    maximum_bridge: int,
) -> list[tuple[str, int, int] | None]:
    """Propage les aplats fiables au travers du texte, jamais des bordures."""
    profiles = [row_background_profile(image, y, left, right, allowed_kinds) for y in range(top, bottom)]
    anchors: list[tuple[str, int, int] | None] = []
    width = right - left
    for positions, is_border in profiles:
        ranked = sorted(allowed_kinds, key=lambda kind: len(positions[kind]), reverse=True)
        kind = ranked[0]
        count = len(positions[kind])
        runner_up = len(positions[ranked[1]]) if len(ranked) > 1 else 0
        reliable = count >= width * 0.30 and count >= max(1, runner_up) * 1.50
        matches = positions[kind]
        anchors.append((kind, matches[0], matches[-1] + 1) if reliable and not is_border else None)

    # Une barre horizontale d'une lettre ou d'une icone peut ponctuellement
    # ressembler a un aplat de la couleur opposee. Une decision isolee qui ne
    # concorde pas avec les lignes voisines est donc retiree avant propagation.
    cleaned_anchors = anchors.copy()
    vote_radius = max(3, round(image.height * 0.014))
    for index, anchor in enumerate(anchors):
        if anchor is None:
            continue
        neighbour_kinds = [
            candidate[0]
            for candidate in anchors[max(0, index - vote_radius) : index + vote_radius + 1]
            if candidate is not None
        ]
        if not neighbour_kinds:
            continue
        votes = {kind: neighbour_kinds.count(kind) for kind in allowed_kinds}
        majority = max(allowed_kinds, key=votes.__getitem__)
        if majority != anchor[0] and votes[majority] >= 3 and votes[majority] > votes[anchor[0]]:
            cleaned_anchors[index] = None
    anchors = cleaned_anchors

    previous: list[int | None] = []
    last: int | None = None
    for index, anchor in enumerate(anchors):
        if anchor is not None:
            last = index
        previous.append(last)
    following: list[int | None] = [None] * len(anchors)
    last = None
    for index in range(len(anchors) - 1, -1, -1):
        if anchors[index] is not None:
            last = index
        following[index] = last

    plan = anchors.copy()
    for index, (profile, is_border) in enumerate(profiles):
        if plan[index] is not None or is_border:
            continue
        before, after = previous[index], following[index]
        if before is None or after is None or after - before > maximum_bridge:
            continue
        before_span, after_span = anchors[before], anchors[after]
        assert before_span is not None and after_span is not None
        if before_span[0] != after_span[0]:
            continue
        ratio = (index - before) / max(1, after - before)
        span_left = round(before_span[1] + (after_span[1] - before_span[1]) * ratio)
        span_right = round(before_span[2] + (after_span[2] - before_span[2]) * ratio)
        plan[index] = before_span[0], span_left, span_right

    # Les limites sont lissees localement entre lignes d'un meme aplat. Cela
    # comble une icone qui masque le fond pres d'un bord, sans adopter une
    # largeur globale susceptible de rogner la bordure manuscrite.
    smoothed = plan.copy()
    for index, span in enumerate(plan):
        if span is None or profiles[index][1]:
            continue
        kind = span[0]
        boundary_radius = max(2, round(image.height * (0.04 if kind == "black" else 0.007)))
        neighbours = [
            candidate
            for candidate in plan[max(0, index - boundary_radius) : index + boundary_radius + 1]
            if candidate is not None and candidate[0] == kind
        ]
        if len(neighbours) < 3:
            continue
        lefts = sorted(candidate[1] for candidate in neighbours)
        rights = sorted(candidate[2] for candidate in neighbours)
        if kind == "black":
            smoothed[index] = kind, lefts[len(lefts) // 5], rights[(len(rights) * 4) // 5]
        else:
            smoothed[index] = kind, lefts[len(lefts) // 2], rights[len(rights) // 2]
    return smoothed


def neutralize(image: Image.Image) -> tuple[Image.Image, int]:
    result = image.convert("RGBA")
    original = result.copy()
    width, height = result.size
    pixels = result.load()

    # Seule la cellule du nom est neutralisee. Les cellules laterales, leurs
    # symboles et toutes leurs bordures restent strictement inchangees.
    header_left, header_right = name_fill_bounds(result)
    header_top, header_bottom = round(height * 0.04), round(height * 0.14)
    header_plan = fill_plan(
        result,
        header_top,
        header_bottom,
        header_left,
        header_right,
        allowed_kinds=("white",),
        maximum_bridge=max(8, round(height * 0.08)),
    )
    for y, span in zip(range(header_top, header_bottom), header_plan):
        if span is None:
            continue
        _, left, right = span
        for x in range(left, right):
            pixels[x, y] = WHITE

    consequence_top = find_consequences_top(result)
    consequence_bottom = round(height * 0.966)
    scan_left, scan_right = round(width * 0.025), round(width * 0.975)
    colors = {"white": WHITE, "offwhite": WHITE, "black": BLACK, "grey": GREY}
    consequence_plan = fill_plan(
        result,
        consequence_top,
        consequence_bottom,
        scan_left,
        scan_right,
        allowed_kinds=("black", "white", "grey", "offwhite"),
        maximum_bridge=max(12, round(height * 0.10)),
    )
    for y, span in zip(range(consequence_top, consequence_bottom), consequence_plan):
        if span is None:
            # Les lignes de bordure sont volontairement exclues : elles ne
            # contiennent pas assez de pixels de la couleur exacte de l'aplat.
            continue
        kind, left, right = span
        color = colors[kind]
        for x in range(left, right):
            pixels[x, y] = color

    # Le remplissage peut s'etendre sous une icone placee contre un bord. Les
    # traits sombres originaux des deux bordures laterales sont restaures apres
    # coup, contrairement aux pixels clairs de l'icone qui doivent disparaitre.
    original_pixels = original.load()
    side_width = round(width * 0.09)
    for y in range(consequence_top, consequence_bottom):
        for x in list(range(0, side_width)) + list(range(width - side_width, width)):
            if luminance(original_pixels[x, y]) < 27:
                pixels[x, y] = original_pixels[x, y]

    return result, consequence_top


def find_prota_frame_top(image: Image.Image) -> int:
    """Trouve les premieres lignes blanches stables du cadre inferieur."""
    width, height = image.size
    pixels = image.load()
    start, stop = round(height * 0.35), round(height * 0.75)
    left, right = round(width * 0.08), round(width * 0.92)
    fractions: list[float] = []
    for y in range(start, stop):
        white = sum(min(pixels[x, y][:3]) > 245 for x in range(left, right))
        fractions.append(white / max(1, right - left))
    window = max(5, round(height * 0.009))
    for index in range(len(fractions) - window + 1):
        if sum(value > 0.50 for value in fractions[index : index + window]) >= window - 1:
            return start + index
    raise ValueError("Cadre inferieur PROTA introuvable")


def connected_component_boxes(
    image: Image.Image,
    box: tuple[int, int, int, int],
    predicate: Callable[[tuple[int, ...]], bool],
    minimum_pixels: int,
) -> list[tuple[int, int, int, int, int]]:
    """Retourne (surface, gauche, haut, droite, bas) pour les formes detectees."""
    left, top, right, bottom = box
    pixels = image.load()
    eligible = {
        (x, y)
        for y in range(top, bottom)
        for x in range(left, right)
        if predicate(pixels[x, y])
    }
    boxes: list[tuple[int, int, int, int, int]] = []
    while eligible:
        seed = eligible.pop()
        stack = [seed]
        count = 0
        min_x = max_x = seed[0]
        min_y = max_y = seed[1]
        while stack:
            x, y = stack.pop()
            count += 1
            min_x, max_x = min(min_x, x), max(max_x, x)
            min_y, max_y = min(min_y, y), max(max_y, y)
            for neighbour in (
                (x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1),
                (x - 1, y - 1), (x + 1, y - 1), (x - 1, y + 1), (x + 1, y + 1),
            ):
                if neighbour in eligible:
                    eligible.remove(neighbour)
                    stack.append(neighbour)
        if count >= minimum_pixels:
            boxes.append((count, min_x, min_y, max_x + 1, max_y + 1))
    return boxes


def expanded_box(
    box: tuple[int, int, int, int], padding: int, width: int, height: int
) -> tuple[int, int, int, int]:
    left, top, right, bottom = box
    return max(0, left - padding), max(0, top - padding), min(width, right + padding), min(height, bottom + padding)


def neutralize_prota(image: Image.Image) -> tuple[Image.Image, int]:
    """Efface le nom et le texte d'une PROTA en preservant cadres et symboles."""
    result = image.convert("RGBA")
    original = result.copy()
    width, height = result.size
    pixels = result.load()

    # Le fond de l'en-tete PROTA est vert tres pale. Le nettoyage s'arrete
    # largement avant les symboles situes a droite.
    header_color = (234, 245, 236, 255)
    header_left, header_right = round(width * 0.055), round(width * 0.72)
    for y in range(round(height * 0.035), round(height * 0.15)):
        matches = [
            x
            for x in range(header_left, header_right)
            if max(abs(pixels[x, y][channel] - header_color[channel]) for channel in range(3)) <= 9
        ]
        if len(matches) < (header_right - header_left) * 0.22:
            continue
        for x in range(matches[0], matches[-1] + 1):
            pixels[x, y] = header_color

    frame_top = find_prota_frame_top(original)
    frame_bottom = round(height * 0.965)
    frame_left, frame_right = round(width * 0.025), round(width * 0.975)

    # Seules les grandes zones grises structurelles sont restaurees apres le
    # remplissage. Les pictogrammes colores et les icones noires sont effaces.
    region = (frame_left, frame_top, frame_right, frame_bottom)
    protected: list[tuple[int, int, int, int]] = []
    grey_boxes = connected_component_boxes(
        original,
        region,
        lambda pixel: 195 <= luminance(pixel) <= 230 and max(pixel[:3]) - min(pixel[:3]) <= 8,
        minimum_pixels=max(150, round(width * height * 0.001)),
    )
    for _, left, top, right, bottom in grey_boxes:
        if (right - left) >= width * 0.08 and (bottom - top) >= height * 0.06:
            protected.append(expanded_box((left, top, right, bottom), round(width * 0.012), width, height))

    lower_plan = fill_plan(
        result,
        frame_top,
        frame_bottom,
        frame_left,
        frame_right,
        allowed_kinds=("white",),
        maximum_bridge=max(20, round(height * 0.12)),
    )
    for y, span in zip(range(frame_top, frame_bottom), lower_plan):
        if span is None:
            continue
        _, left, right = span
        for x in range(left, right):
            pixels[x, y] = WHITE

    for box in protected:
        result.alpha_composite(original.crop(box), (box[0], box[1]))
    return result, frame_top


def ordered_sources(input_dir: Path) -> tuple[list[Path], list[Path], list[Path]]:
    """Retourne cartes a traiter, cartes speciales, puis PNG ignores."""
    pngs = sorted(input_dir.glob("*.png"), key=lambda path: path.name.upper())
    rural: list[tuple[int, Path]] = []
    urban: list[tuple[int, Path]] = []
    prota: list[tuple[int, Path]] = []
    named_specials: dict[str, Path] = {}
    ignored: list[Path] = []

    for path in pngs:
        name = path.name.upper()
        regular_match = REGULAR_CARD_RE.match(name)
        if regular_match:
            family, number_text = regular_match.groups()
            number = int(number_text)
            if number == 0:
                named_specials[f"{family}-00.PNG"] = path
            elif family == "RURAL":
                rural.append((number, path))
            else:
                urban.append((number, path))
            continue
        prota_match = PROTA_RE.match(name)
        if prota_match:
            prota.append((int(prota_match.group(1)), path))
        elif name == "DEFAULT.PNG":
            named_specials[name] = path
        else:
            ignored.append(path)

    regular = [path for _, path in sorted(rural)] + [path for _, path in sorted(urban)]
    special = [path for _, path in sorted(prota)]
    special.extend(
        named_specials[name]
        for name in ("RURAL-00.PNG", "URBAN-00.PNG", "DEFAULT.PNG")
        if name in named_specials
    )
    return regular, special, ignored


def choose_sprite_layout(
    regular_count: int,
    special_count: int,
    source_size: tuple[int, int],
    max_size: int,
    gap: int,
) -> tuple[int, int, int, int, int]:
    """Maximise la surface d'une carte avec les speciales en derniere ligne."""
    source_width, source_height = source_size
    minimum_columns = max(1, special_count)
    maximum_columns = max(minimum_columns, regular_count)
    candidates: list[tuple[int, int, int, int, int, int]] = []
    for columns in range(minimum_columns, maximum_columns + 1):
        regular_rows = math.ceil(regular_count / columns) if regular_count else 0
        rows = regular_rows + (1 if special_count else 0)
        available_width = max_size - gap * (columns - 1)
        available_height = max_size - gap * (rows - 1)
        if available_width <= 0 or available_height <= 0:
            continue
        scale = min(
            available_width / (columns * source_width),
            available_height / (rows * source_height),
        )
        card_width = min(available_width // columns, round(source_width * scale))
        card_height = min(available_height // rows, round(source_height * scale))
        candidates.append((card_width * card_height, card_width, card_height, columns, rows, regular_rows))
    if not candidates:
        raise ValueError("Impossible de construire une sprite sheet avec ces parametres")
    _, card_width, card_height, columns, rows, regular_rows = max(candidates)
    return columns, rows, regular_rows, card_width, card_height


def build_sprite(
    regular: list[Path],
    special: list[Path],
    prepared_images: dict[str, Image.Image],
    sprite_path: Path,
    max_size: int,
    gap: int,
    max_file_bytes: int,
) -> tuple[int, int, int, int, int, int]:
    ordered = regular + special
    if not ordered:
        raise ValueError("Aucune image reconnue pour la sprite sheet")

    source_size = prepared_images[ordered[0].name].size
    for source in ordered[1:]:
        image = prepared_images[source.name]
        if image.size[0] * source_size[1] != image.size[1] * source_size[0]:
            raise ValueError(f"Ratio incompatible pour {source.name}: {image.size}, attendu {source_size}")

    current_max_size = max_size
    while True:
        columns, rows, regular_rows, card_width, card_height = choose_sprite_layout(
            len(regular), len(special), source_size, current_max_size, gap
        )
        sprite_width = columns * card_width + (columns - 1) * gap
        sprite_height = rows * card_height + (rows - 1) * gap
        sprite = Image.new("RGBA", (sprite_width, sprite_height), (0, 0, 0, 0))

        placements: list[tuple[Path, int, int]] = []
        for index, source in enumerate(regular):
            placements.append((source, index % columns, index // columns))
        for index, source in enumerate(special):
            placements.append((source, index, regular_rows))

        for source, column, row in placements:
            resized = prepared_images[source.name].resize(
                (card_width, card_height), Image.Resampling.LANCZOS
            )
            x = column * (card_width + gap)
            y = row * (card_height + gap)
            sprite.alpha_composite(resized, (x, y))

        sprite.save(sprite_path, format="PNG", optimize=True)
        file_bytes = sprite_path.stat().st_size
        if max_file_bytes == 0 or file_bytes <= max_file_bytes:
            for source, column, row in placements:
                x = column * (card_width + gap)
                y = row * (card_height + gap)
                print(f"sprite {source.name}: x={x}, y={y}")
            return sprite_width, sprite_height, card_width, card_height, columns, file_bytes

        # Le poids d'un PNG varie approximativement avec sa surface. La marge
        # evite une deuxieme passe pour quelques octets dus a la compression.
        reduction = math.sqrt(max_file_bytes / file_bytes) * 0.98
        next_max_size = min(current_max_size - 1, math.floor(current_max_size * reduction))
        print(
            f"Sprite trop lourde ({file_bytes / 1_000_000:.2f} Mo); "
            f"nouvel essai avec --max-size={next_max_size}"
        )
        current_max_size = next_max_size


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Prepare les cartes et produit leur sprite sheet."
    )
    parser.add_argument("input_dir", type=Path, help="Dossier contenant les PNG sources")
    parser.add_argument("output_dir", type=Path, help="Dossier recevant les PNG neutralises")
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="Autorise le remplacement de fichiers deja presents dans le dossier cible",
    )
    parser.add_argument("--sprite-name", default="cards-sprite.png", help="Nom du PNG de sprite sheet")
    parser.add_argument("--gap", type=int, default=2, help="Gouttiere transparente en pixels (defaut: 2)")
    parser.add_argument("--max-size", type=int, default=4096, help="Largeur/hauteur maximale (defaut: 4096)")
    parser.add_argument(
        "--max-sprite-mb",
        type=float,
        default=9.0,
        help="Poids maximal du sprite en Mo decimaux; 0 desactive la limite (defaut: 9)",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.gap < 0 or args.max_size <= 0 or args.max_sprite_mb < 0:
        print(
            "--gap et --max-sprite-mb doivent etre positifs ou nuls, "
            "et --max-size strictement positif",
            file=sys.stderr,
        )
        return 1
    regular, special, ignored = ordered_sources(args.input_dir)
    sources = regular + special
    if not sources:
        print(f"Aucun PNG reconnu dans {args.input_dir}", file=sys.stderr)
        return 1

    args.output_dir.mkdir(parents=True, exist_ok=True)
    sprite_path = args.output_dir / args.sprite_name
    if sprite_path.exists() and not args.overwrite:
        print(
            f"{sprite_path} existe deja; utilisez --overwrite pour le remplacer.",
            file=sys.stderr,
        )
        return 2

    prepared_images: dict[str, Image.Image] = {}
    for source in regular:
        with Image.open(source) as image:
            result, consequence_top = neutralize(image)
            prepared_images[source.name] = result
            print(f"{source.name}: consequences detectees a y={consequence_top}/{image.height}")

    for source in special:
        if PROTA_RE.match(source.name):
            with Image.open(source) as image:
                result, frame_top = neutralize_prota(image)
                prepared_images[source.name] = result
                print(f"{source.name}: nom et cadre inferieur neutralises a y={frame_top}/{image.height}")
        else:
            with Image.open(source) as image:
                prepared_images[source.name] = image.convert("RGBA")
            print(f"{source.name}: ajoutee sans modification")

    max_file_bytes = round(args.max_sprite_mb * 1_000_000)
    sprite_width, sprite_height, card_width, card_height, columns, file_bytes = build_sprite(
        regular, special, prepared_images, sprite_path, args.max_size, args.gap, max_file_bytes
    )
    if ignored:
        print("PNG ignores: " + ", ".join(path.name for path in ignored))
    print(f"{len(sources)} carte(s) integree(s); seule la sprite sheet a ete ecrite")
    print(
        f"Sprite: {sprite_path} ({sprite_width}x{sprite_height}), "
        f"cartes {card_width}x{card_height}, {columns} colonne(s), gouttiere {args.gap}px, "
        f"{file_bytes / 1_000_000:.2f} Mo"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
