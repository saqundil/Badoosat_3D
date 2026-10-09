<?php

declare(strict_types=1);

/*
 * The models a hero slide may show.
 *
 * Every entry is a .glb that ships with the project. There is no upload door
 * and no free-text path on the slide any more: an administrator picks a key
 * from this list and HeroSlideService refuses anything that is not in it, so
 * the only files the hero can ever fetch are the ones listed here.
 *
 * `garment` names the entry in config/garments.php that carries this model's
 * print geometry -- the UV rectangles artwork is fitted into. A model with no
 * matching garment entry cannot take a design, which is why the two keys are
 * written side by side rather than assumed to be the same word.
 */

return [
    'tshirt' => [
        'label'   => 'تيشيرت',
        'file'    => '/models/tshirt.glb',
        'garment' => 'tshirt',
    ],
];
