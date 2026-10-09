/**
 * Turns the Sketchfab download into public/models/tshirt.glb.
 *
 * One-off developer step, run on a workstation, never on the server:
 *   npm i -D @gltf-transform/core @gltf-transform/extensions \
 *            @gltf-transform/functions sharp
 *   node tools/prepare-model.mjs basic_t-shirt.glb public/models/tshirt.glb
 *
 * What it does and why:
 *  1. KHR_materials_pbrSpecularGlossiness is dead and unsupported in current
 *     three.js, so the material is converted to metallic-roughness.
 *  2. That conversion leaves a specular/ior pair carrying flat textures which
 *     would force MeshPhysicalMaterial. They are dropped.
 *  3. The diffuse map is a 1024x1024 block of #46413A with no baked lighting,
 *     and the metallic-roughness map is flat too. Both become scalar factors,
 *     leaving baseColorFactor white so material.color alone tints the garment.
 *  4. The normal map is kept and recompressed to WebP; doubleSided stays true.
 *  5. asset.extras carries the CC-BY attribution and is never touched.
 *
 * No mesh compression: Draco and Meshopt both need a decoder shipped beside
 * three.js, and this project has no build step and a fixed vendor file list.
 */

import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import { metalRough, prune, dedup, flatten, join, textureCompress } from '@gltf-transform/functions';
import sharp from 'sharp';
import { stat } from 'node:fs/promises';

const SIZE_LIMIT = 800 * 1024;
const input = process.argv[2] ?? 'basic_t-shirt.glb';
const output = process.argv[3] ?? 'public/models/tshirt.glb';

const io = new NodeIO().registerExtensions(ALL_EXTENSIONS);
const doc = await io.read(input);
const root = doc.getRoot();

await doc.transform(metalRough());

const material = root.listMaterials()[0];

// The converted metallic-roughness map is a flat colour; read it once and keep
// the value as a factor instead of a 1024x1024 texture.
const mrTexture = material.getMetallicRoughnessTexture();
let roughness = 0.96;

if (mrTexture) {
    const { channels } = await sharp(Buffer.from(mrTexture.getImage())).stats();
    roughness = +(channels[1].mean / 255).toFixed(3);
}

material.setBaseColorTexture(null);
material.setBaseColorFactor([1, 1, 1, 1]);
material.setMetallicRoughnessTexture(null);
material.setMetallicFactor(0);
material.setRoughnessFactor(roughness);
material.setDoubleSided(true);

for (const name of ['KHR_materials_specular', 'KHR_materials_ior']) {
    material.getExtension(name)?.dispose();
    material.setExtension(name, null);
}

for (const extension of root.listExtensionsUsed()) {
    if (extension.extensionName.startsWith('KHR_materials_')) {
        extension.dispose();
    }
}

await doc.transform(
    prune({ keepExtras: true }),
    dedup(),
    flatten(),
    join(),
    textureCompress({ encoder: sharp, targetFormat: 'webp' })
);

await io.write(output, doc);

const { size } = await stat(output);
const extras = root.getAsset().extras;

console.log(output);
console.log('  size            ' + (size / 1024).toFixed(1) + ' KB  (limit ' +
    (SIZE_LIMIT / 1024) + ' KB)  ' + (size <= SIZE_LIMIT ? 'OK' : 'TOO LARGE'));
console.log('  extensions      ' + (root.listExtensionsUsed().map((e) => e.extensionName).join(', ') || 'none'));
console.log('  textures        ' + root.listTextures().map((t) => t.getMimeType()).join(', '));
console.log('  roughnessFactor ' + roughness + ', metallicFactor 0, baseColorFactor white');
console.log('  doubleSided     ' + material.getDoubleSided());
console.log('  attribution     ' + (extras?.author ?? 'MISSING') + ' / ' + (extras?.license ?? 'MISSING'));

if (size > SIZE_LIMIT || !extras?.author) {
    process.exitCode = 1;
}
