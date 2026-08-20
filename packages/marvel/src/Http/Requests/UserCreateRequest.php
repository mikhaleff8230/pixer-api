<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


class UserCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $isPublicRegistration = $this->route()?->getActionMethod() === 'register';

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string'],
            'accept_terms' => [$isPublicRegistration ? 'required' : 'sometimes', 'accepted'],
            'accept_privacy' => [$isPublicRegistration ? 'required' : 'sometimes', 'accepted'],
            'marketing_email_consent' => ['sometimes', 'boolean'],
            'marketing_push_consent' => ['sometimes', 'boolean'],
            'shop_id' => ['nullable', 'exists:Marvel\Database\Models\Shop,id'],
            'profile'  => ['array'],
            'address'  => ['array'],
            // 'shop'  => ['array'],
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'      => 'Name is required',
            'name.string'        => 'Name is not a valid string',
            'name.max:255'       => 'Name can not be more than 255 character',
            'email.required'     => 'email is required',
            'email.email'        => 'email is not a valid email address',
            'email.unique:users' => 'email must be unique',
            'password.required'  => 'password is required',
            'password.string'    => 'password is not a valid string',
            'accept_terms.accepted' => 'Необходимо принять пользовательское соглашение и публичную оферту',
            'accept_terms.required' => 'Необходимо принять пользовательское соглашение и публичную оферту',
            'accept_privacy.accepted' => 'Необходимо дать согласие на обработку персональных данных',
            'accept_privacy.required' => 'Необходимо дать согласие на обработку персональных данных',
            'address.array'      => 'address is not a valid json',
            'profile.array'      => 'profile is not a valid json',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
