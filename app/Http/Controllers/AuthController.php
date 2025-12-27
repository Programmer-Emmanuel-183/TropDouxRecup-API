<?php

namespace App\Http\Controllers;

use App\Mail\OtpMail;
use App\Models\Abonnement;
use App\Models\Admin;
use App\Models\Marchand;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Query;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\error;

class AuthController extends Controller
{
    //Inscription
    public function register_marchand(Request $request){
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string',
            'email' => 'required|email',
            'telephone' => 'required|digits:10',
            'password' => 'required|min:4',
            'id_localite' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
                $marchand = Marchand::where('email_marchand', $request->email)
                ->orWhere('tel_marchand', $request->telephone)
                ->first();
            
                // $code_otp = rand(1000, 9999);
                $code_otp = substr($request->telephone, -4);

                if ($marchand) {
                    if ($marchand->is_verify) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cet email ou numéro de téléphone est déjà vérifié.'
                        ], 409);
                    }

                    // Mise à jour si le marchand n'est pas vérifié
                    $marchand->update([
                        'nom_marchand' => $request->nom,
                        'password_marchand' => Hash::make($request->password),
                        'code_otp' => $code_otp,
                        'otp_expire_at' => now()->addMinutes(10),
                    ]);

                } else {
                    // Création d'un nouveau compte
                    $marchand = Marchand::create([
                        'nom_marchand' => $request->nom,
                        'email_marchand' => $request->email,
                        'tel_marchand' => $request->telephone,
                        'id_commune' => $request->id_localite,
                        'password_marchand' => Hash::make($request->password),
                        'code_otp' => $code_otp,
                        'otp_expire_at' => now()->addMinutes(10),
                    ]);
                }

                // try{
                //     Mail::to($marchand->email_marchand)->send(new OtpMail($code_otp, $marchand));
                // }
                // catch(QueryException $e){
                //     return response()->json([
                //         'success' => false,
                //         'message' => 'Erreur lors de l’envoi d’email',
                //         'erreur' => $e->getMessage()
                //     ],500);
                // }

                return response()->json([
                    'success' => true,
                    'role' => 'marchand',
                    'message' => 'Inscription réussie. Un nouveau code OTP a été envoyé par mail.',
                ], 200);
            

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’inscription',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    //Inscription client
    public function register_client(Request $request){
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string',
            'email' => 'required|email',
            'telephone' => 'required|digits:10',
            'password' => 'required|min:4',
        ]);
        try{
            $client = new User();
            $client->nom_client = $request->nom;
            $client->email_client = $request->email;
            $client->tel_client = $request->telephone;
            $client->password_client = Hash::make($request->password);
            $client->save();

            $token = $client->createToken('ClientToken')->plainTextToken;
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'email' => $client->email_client,
                    'telephone' => $client->tel_client,
                    'password' => $client->password_client,
                    'token' => $token,
                    'role' => 'client',
                    'device_token' => $client->device_token
                ],
                'message' => 'Inscription reussie'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur survenue lors de l’inscription',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    //Vérification OTP Marchand
    public function verify_otp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email_marchand' => 'required|email',
            'code_otp' => 'required|digits:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $marchand = Marchand::where('email_marchand', $request->email_marchand)->first();

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ], 404);
            }

            if ($marchand->is_verify) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet email est déjà vérifié'
                ], 409);
            }

            if (
                hash_equals($marchand->code_otp, $request->code_otp) &&
                Carbon::parse($marchand->otp_expire_at)->isFuture()
            ) {

                $token = $marchand->createToken('MarchandToken')->plainTextToken;
                $abonnement = Abonnement::where('type_abonnement', 'debutant')->first();
                $marchand->update([
                    'is_verify' => true,
                    'code_otp' => null,
                    'otp_expire_at' => null,
                    'id_abonnement' => $abonnement->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Vérification réussie',
                    'data' => [
                        'id' => $marchand->id,
                        'nom_marchand' => $marchand->nom_marchand,
                        'email_marchand' => $marchand->email_marchand,
                        'tel_marchand' => $marchand->tel_marchand,
                        'localite' => $marchand->commune->localite,
                        'type_abonnement' => $abonnement->type_abonnement,
                        'device_token' => $marchand->device_token,
                        'token' => $token
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Code OTP invalide ou expiré'
            ], 403);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la vérification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

//Renvoyer OTP marchand
    public function resend_otp(Request $request){
        $validator = Validator::make($request->all(), [
            'email_marchand' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $marchand = Marchand::where('email_marchand', $request->email_marchand)->first();

        if (!$marchand) {
            return response()->json([
                'success' => false,
                'message' => 'Marchand introuvable'
            ], 404);
        }

        if ($marchand->is_verify) {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte a déjà été vérifié.'
            ], 409);
        }

        // $code_otp = rand(1000, 9999);
        $code_otp = substr($marchand->tel_marchand, -4);

        $marchand->update([
            'code_otp' => $code_otp,
            'otp_expire_at' => now()->addMinutes(10),
        ]);

        // Mail::to($marchand->email_marchand)->send(new OtpMail($code_otp, $marchand));

        return response()->json([
            'success' => true,
            'message' => 'Nouveau code OTP envoyé.'
        ]);
    }

    //Connexion Marchand et client
    public function login(Request $request){

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ], [
            'email.required' => 'L’email est obligatoire',
            'email.email' => 'L’email doit être de type email',
            'password.required' => 'Le mot de passe est obligatoire'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $marchand = Marchand::where('email_marchand', $request->email)->first();
            if ($marchand && Hash::check($request->password, $marchand->password_marchand)) {
                $token = $marchand->createToken('MarchandToken')->plainTextToken;
                $marchand->load('abonnement');
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $marchand->id,
                        'nom' => $marchand->nom_marchand,
                        'email' => $marchand->email_marchand,
                        'telephone' => $marchand->tel_marchand,
                        'localite' => $marchand->commune->localite,
                        'role' => 'marchand',
                        'type_abonnement' => $marchand->abonnement->type_abonnement,
                        'device_token' => $marchand->device_token,
                        'token' => $token
                    ],
                    'message' => 'Connexion réussie'
                ], 200);
            }

            $client = User::where('email_client', $request->email)->first();
            if ($client && Hash::check($request->password, $client->password_client)) {
                $token = $client->createToken('ClientToken')->plainTextToken;
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $client->id,
                        'nom' => $client->nom_client,
                        'email' => $client->email_client,
                        'telephone' => $client->tel_client,
                        'role' => 'client',
                        'device_token' => $client->device_token,
                        'token' => $token
                    ],
                    'message' => 'Connexion réussie'
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Identifiants incorrects',
            ], 401);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    //info du profil marchand
    public function info_profil_marchand(Request $request){
        $user = $request->user();
        try{
            $marchand = Marchand::where('id', $user->id)->first();
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé' 
                ],404);
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'email' => $marchand->email_marchand,
                    'telephone' => $marchand->tel_marchand,
                    'image_profil' => $marchand->image_marchand,
                    'role' => 'marchand',
                    'solde' => $marchand->solde_marchand,
                    'abonnement' => $marchand->abonnement->type_abonnement ?? null,
                    'localite' => $marchand->commune ? [
                        'id' => $marchand->commune->id,
                        'libelle' => $marchand->commune->localite,
                    ] : null,
                    'device_token' => $marchand->device_token,
                ],
                'message' => 'Information du profil affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des infos du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    //Info profil client
    public function info_profil_client(Request $request){
        $user = $request->user();
        try{
            $client = User::where('id', $user->id)->first();
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé' 
                ],404);
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'email' => $client->email_client,
                    'telephone' => $client->tel_client,
                    'image_profil' => $client->image_client,
                    'role' => 'client',
                    'device_token' => $client->device_token,
                ],
                'message' => 'Information du profil affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des infos de client',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    //Mise a jour du profil marchand
    public function update_profil_marchand(Request $request){
        $user = $request->user();
        $marchand = Marchand::where('id', $user->id)->first();
        if(!$marchand){
            return response()->json([
                'success' => false,
                'message' => 'Marchand introuvable'
            ],404);
        }
        try{
            $validator = Validator::make($request->all(), [
                'nom' => 'string',
                'email' => 'email',
                'telephone' => 'digits:10',
                'image_profil' => 'image|mimes:jpeg,png,jpg|max:2048',
                'id_localite' => 'string'
            ]);
            if($validator->fails()){
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ],422);
            }
            $image = $this->uploadImageToHosting($request->file('image_profil'));

            $marchand->nom_marchand = $request->nom ?? $marchand->nom_marchand;
            $marchand->email_marchand = $request->email ?? $marchand->email_marchand;
            $marchand->tel_marchand = $request->telephone ?? $marchand->tel_marchand;
            $marchand->image_marchand = $image ?? $marchand->image_marchand;
            $marchand->id_commune = $request->id_localite ?? $marchand->id_commune;
            $marchand->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'email' => $marchand->email_marchand,
                    'telephone' => $marchand->tel_marchand,
                    'image_profil' => $marchand->image_marchand,
                    'solde' => $marchand->solde_marchand,
                    'abonnement' => $marchand->abonnement->type_abonnement,
                    'localite' => $marchand->commune->localite,
                    'device_token' => $marchand->device_token,

                ],
                'message' => 'Info marchand modifié avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification des infos du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

        //Mise a jour du profil client
        public function update_profil_client(Request $request){
        $user = $request->user();
        $client = User::where('id', $user->id)->first();
        if(!$client){
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ],404);
        }
        try{
            $validator = Validator::make($request->all(), [
                'nom' => 'string',
                'email' => 'email',
                'telephone' => 'digits:10',
                'image_profil' => 'image|mimes:jpeg,png,jpg|max:2048',
            ]);
            if($validator->fails()){
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ],422);
            }
            $image = $this->uploadImageToHosting($request->file('image_profil'));

            $client->nom_client = $request->nom ?? $client->nom_client;
            $client->email_client = $request->email ?? $client->email_client;
            $client->tel_client = $request->telephone ?? $client->tel_client;
            $client->image_client = $image ?? $client->image_client;
            $client->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'email' => $client->email_client,
                    'telephone' => $client->tel_client,
                    'image_profil' => $client->image_client,
                    'device_token' => $client->device_token,
                ],
                'message' => 'Info client modifié avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification des infos du client',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    private function uploadImageToHosting($image){
        $apiKey = '9b1ab6564d99aab6418ad53d3451850b';

        // Vérifie que le fichier est une instance valide
        if (!$image->isValid()) {
            throw new \Exception("Fichier image non valide.");
        }

        // Lecture et encodage en base64
        $imageContent = base64_encode(file_get_contents($image->getRealPath()));

        $response = Http::asForm()->post('https://api.imgbb.com/1/upload', [
            'key' => $apiKey,
            'image' => $imageContent,
        ]);

        if ($response->successful()) {
            return $response->json()['data']['url'];
        }

        throw new \Exception("Erreur lors de l'envoi de l'image : " . $response->body());
    }

    //Changement de mot de passe marchand
    public function update_password_marchand(Request $request){
        $user = $request->user();
        try{
            $validator = Validator::make($request->all(),[
                'ancien' => 'required|string|min:4',
                'nouveau' => 'required|string|min:4|confirmed'
            ]);
            if($validator->fails()){
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ],422);
            }
            $marchand = Marchand::where('id', $user->id)->first();
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouve.',
                ],404);
            }
            if(!Hash::check($request->ancien, $marchand->password_marchand)){
                return response()->json([
                    'success' => false,
                    'message' => 'L’ancien mot de passe est incorrect.'
                ],400);
            }

            $marchand->password_marchand = Hash::make($request->nouveau);
            $marchand->save();

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe mis à jour.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du mot de passe du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    //Changement de mot de passe client
    public function update_password_client(Request $request){
        $user = $request->user();
        try{
            $validator = Validator::make($request->all(),[
                'ancien' => 'required|string|min:4',
                'nouveau' => 'required|string|min:4|confirmed'
            ]);
            if($validator->fails()){
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ],422);
            }
            $client = User::where('id', $user->id)->first();
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Clientclient non trouve.',
                ],404);
            }
            if(!Hash::check($request->ancien, $client->password_client)){
                return response()->json([
                    'success' => false,
                    'message' => 'L’ancien mot de passe est incorrect.'
                ],400);
            }
            
            $client->password_client = Hash::make($request->nouveau);
            $client->save();

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe mis à jour.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du mot de passe du client',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    //Connexion administrateur
    public function login_admin(Request $request){
        $validator = Validator::make($request->all(),[
            'email' => 'email|required',
            'password' => 'string|required|min:4'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        $admin = Admin::where('email_admin', $request->email)->first();
        if($admin && Hash::check($request->password, $admin->password_admin)){
            $token = $admin->createToken('AdminToken')->plainTextToken;
            return response()->json([
                'success' => true,
                'data' => [
                    'nom' => $admin->nom_admin,
                    'email' => $admin->email_admin,
                    'telephone' => $admin->tel_admin,
                    'image_profil' => $admin->image_admin,
                    'role' => $admin->role,
                    'token' => $token
                ],
                'message' => 'Connexion de l’admin réussie'
            ],200);
        }
    }

    public function ajout_sub_admin(Request $request){
        $validator = Validator::make($request->all(),[
            'nom_admin' => 'required',
            'email_admin' => 'required|email:admins',
            'tel_admin' => 'required|digits:10', 
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $user = $request->user();
            if(!$user || $user->role != 2){
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin non trouvé ou accès non autorisé'
                ],403);
            }

            $admin = new Admin();
            $admin->nom_admin = $request->nom_admin;
            $admin->email_admin = $request->email_admin;
            $admin->tel_admin = $request->tel_admin;
            $admin->role = 1;
            $admin->password_admin = Hash::make('admin' . substr($admin->tel_admin, 4));
            $admin->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $admin->id,
                    'nom' => $admin->nom_admin,
                    'email' => $admin->email_admin,
                    'telephone' => $admin->tel_admin,
                    'image_profil' => $admin->image_admin,
                    'role' => $admin->role, 
                ],
                'message' => 'Sous admin créé avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de sous administrateur',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function admins(Request $request){
        try{
            $user = $request->user();
            if(!$user || $user->role != 2){
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin non trouvé ou accès non autorisé'
                ],403);
            }

            $admins = Admin::where('role', 1)->orderBy('created_at', 'desc')->get();
            $data = $admins->map(function($admin){
                return [
                    'id' => $admin->id,
                    'nom' => $admin->nom_admin,
                    'email' => $admin->email_admin,
                    'telephone' => $admin->tel_admin,
                    'image_profil' => $admin->image_admin,
                    'role' => $admin->role, 
                    'created_at' => $admin->created_at,
                    'updated_at' => $admin->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des sous administrateurs affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des sous administrateurs',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function admin(Request $request, $id){
        try{
            $user = $request->user();
            if(!$user || $user->role != 2){
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin non trouvé ou accès non autorisé'
                ],403);
            }
            
            $admin = Admin::find($id);
            if(!$admin){
                return response()->json([
                    'success' => false,
                    'message' => 'Sous admin introuvable'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $admin->id,
                    'nom' => $admin->nom_admin,
                    'email' => $admin->email_admin,
                    'telephone' => $admin->tel_admin,
                    'image_profil' => $admin->image_admin,
                    'role' => $admin->role,
                    'created_at' => $admin->created_at,
                    'updated_at' => $admin->updated_at,
                ],
                'message' => 'Sous administrateur affiché avec succès'
            ],200);

        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du sous administrateur',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_admin(Request $request, $id){
        try{
            $user = $request->user();
            if(!$user || $user->role != 2){
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin non trouvé ou accès non autorisé'
                ],403);
            }

            $admin = Admin::find($id);
            if(!$admin){
                return response()->json([
                    'success' => false,
                    'message' => 'Sous admin introuvable'
                ],404);
            }

            $admin->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sous administrateur supprimé avec succès'
            ],200);

        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du sous administrateur',
                'erreur' => $e->getMessage()
            ],500);
        }
    }



}
