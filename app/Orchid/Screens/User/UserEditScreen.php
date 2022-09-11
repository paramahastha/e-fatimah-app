<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Helper\UserActivityHelper;
use App\Models\User;
use App\Models\UserInfo;
use App\Orchid\Layouts\Role\RolePermissionLayout;
use App\Orchid\Layouts\User\UserCredentialLayout;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserPasswordLayout;
use App\Orchid\Layouts\User\UserRoleLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Orchid\Access\UserSwitch;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserEditScreen extends Screen
{
    /**
     * @var User
     */
    public $user;

    /**
     * Query data.
     *
     * @param User $user
     *
     * @return array
     */
    public function query(User $user): iterable
    {
        $user->load(['roles']);

        return [
            'user'       => $user,
            'permission' => $user->getStatusPermission(),
        ];
    }

    /**
     * Display header name.
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return $this->user->exists ? 'Edit User' : 'Create User';
    }

    /**
     * Display header description.
     *
     * @return string|null
     */
    public function description(): ?string
    {
        return 'Details such as name, email and password';
    }

    /**
     * @return iterable|null
     */
    public function permission(): ?iterable
    {
        return [
            'platform.systems.users',
        ];
    }

    /**
     * Button commands.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            // Button::make(__('Impersonate user'))
            //     ->icon('login')
            //     ->confirm('You can revert to your original state by logging out.')
            //     ->method('loginAs')
            //     ->canSee($this->user->exists && \request()->user()->id !== $this->user->id),

            Button::make(__('Remove'))
                ->icon('trash')
                ->confirm(__('Once the account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'))
                ->method('remove')
                ->canSee($this->user->exists),

            Button::make(__('Save'))
                ->icon('check')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(UserEditLayout::class)
                ->title(__('Profile Information'))
                ->description(__('Update your account\'s profile information and email address.')),
                // ->commands(
                //     Button::make(__('Save'))
                //         ->type(Color::DEFAULT())
                //         ->icon('check')
                //         ->canSee($this->user->exists)
                //         ->method('save')
                // ),

            Layout::block(UserCredentialLayout::class)
                ->title(__('Credential'))
                ->description(__('Ensure your account is using a long, random password to stay secure.')),
                // ->commands(
                //     Button::make(__('Save'))
                //         ->type(Color::DEFAULT())
                //         ->icon('check')
                //         ->canSee($this->user->exists)
                //         ->method('save')
                // ),

            Layout::block(UserRoleLayout::class)
                ->title(__('Roles'))
                ->description(__('A Role defines a set of tasks a user assigned the role is allowed to perform.'))
                // ->commands(
                //     Button::make(__('Save'))
                //         ->type(Color::DEFAULT())
                //         ->icon('check')
                //         ->canSee($this->user->exists)
                //         ->method('save')
                // ),

            // Layout::block(RolePermissionLayout::class)
            //     ->title(__('Permissions'))
            //     ->description(__('Allow the user to perform some actions that are not provided for by his roles'))
            //     ->commands(
            //         Button::make(__('Save'))
            //             ->type(Color::DEFAULT())
            //             ->icon('check')
            //             ->canSee($this->user->exists)
            //             ->method('save')
            //     ),

        ];
    }

    /**
     * @param User    $user
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(User $user, Request $request)
    {
        $request->validate([
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
            'user.userInfo.date_of_birth' => 'before:today',
            'user.userInfo.identity_number' => [
                'required',
                Rule::unique(UserInfo::class, 'identity_number')->ignore($user->userInfo),
            ],
        ]);

        // $permissions = collect($request->get('permissions'))
        //     ->map(function ($value, $key) {
        //         return [base64_decode($key) => $value];
        //     })
        //     ->collapse()
        //     ->toArray();

        $userData = $request->get('user');
        if ($user->exists && (string) $userData['password'] === '') {
            // When updating existing user null password means "do not change current password"
            unset($userData['password']);
        } else {
            $userData['password'] = Hash::make($userData['password']);
        }       
        
        $user
        ->fill($userData)            
        // ->fill([                
        //     'permissions' => $permissions,
        // ])
        ->save();
        
        $user->replaceRoles($request->input('user.roles'));

        $userInfo =  $userData["userInfo"];        
     
        $dob = date('Y-m-d', strtotime($userInfo["date_of_birth"]));        

        $userInfoData = [
            'user_id' => $user->id,                   
            'date_of_birth' => $dob,
            'identity_number' => $userInfo["identity_number"],
            'gender' => $userInfo["gender"],
            'phone_number' => $userInfo["phone_number"],
            'address' => $userInfo["address"],
            'photo' => $userInfo["photo"]
        ];        
        
        if ($user->userInfo == null) {                            
            UserInfo::create($userInfoData);        
        } else {                        
            $user->userInfo()->update($userInfoData);
        }
        
        UserActivityHelper::record($this->user->exists ? 'Edit User' : 'Create User', 
            UserActivityHelper::$USER_MANAGEMENT);

        Toast::info(__('User was saved.'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @param User $user
     *
     * @throws \Exception
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     */
    public function remove(User $user)
    {
        $user->delete();

        UserActivityHelper::record('Remove User', UserActivityHelper::$USER_MANAGEMENT);

        Toast::info(__('User was removed'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @param User $user
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function loginAs(User $user)
    {
        UserSwitch::loginAs($user);

        Toast::info(__('You are now impersonating this user'));

        return redirect()->route(config('platform.index'));
    }
}
