<?php
/**
 * Created by PhpStorm.
 * User: leo108
 * Date: 2016/10/23
 * Time: 16:23
 */

namespace JuheData\CAS\Responses;

use JuheData\CAS\Contracts\Responses\AuthenticationFailureResponse;

class JsonAuthenticationFailureResponse extends BaseJsonResponse implements AuthenticationFailureResponse
{
    /**
     * JsonAuthenticationFailureResponse constructor.
     */
    public function __construct()
    {
        $this->data = ['serviceResponse' => ['authenticationFailure' => []]];
    }

    /**
     * @param string $code
     * @param string $description
     * @return $this
     */
    public function setFailure($code, $description)
    {
        $this->data['serviceResponse']['authenticationFailure']['code']        = $code;
        $this->data['serviceResponse']['authenticationFailure']['description'] = $description;

        return $this;
    }
}
