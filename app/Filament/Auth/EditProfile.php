<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;

/** Profil sans mot de passe : la connexion se fait par lien magique. */
class EditProfile extends BaseEditProfile
{
    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
        ]);
    }
}
