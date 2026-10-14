<?php

/** Minimal structurally valid exports for count boundaries, not game-compatible DATs. */
function packageSii(int $count): string
{
    $sii = "SiiNunit\n{\nserver_packages_info : info {\nversion: 1\nmap_name: europe\nmod_list: {$count}\n";
    for ($index = 0; $index < $count; $index++) {
        $sii .= "mod_list[{$index}]: mod.{$index}\n";
    }
    $sii .= "}\n";
    for ($index = 0; $index < $count; $index++) {
        $sii .= "server_mod_detail : mod.{$index} {\nmod_name: \"Mod {$index}\"\npackage_name: \"mod_{$index}\"\nmod_id: {$index}\nchecksum: {$index}\nworkshop_mod: false\noptional_mod: false\n}\n";
    }

    return $sii."}\n";
}
