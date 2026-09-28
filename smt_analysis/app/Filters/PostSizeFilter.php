<?php

namespace App\Filters;

use App\Libraries\UploadLimits;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class PostSizeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $limit = UploadLimits::bytes((string) ini_get('post_max_size'));
        if ($request->getMethod() === 'POST' && $limit > 0 && (int) $request->getServer('CONTENT_LENGTH') > $limit) {
            // PHP discards both the file and CSRF field before CodeIgniter runs.
            // Reject the request before CSRF validation; never process its data.
            return service('response')->setStatusCode(413)->setBody(view('errors/upload_too_large', ['limit' => UploadLimits::label($limit)]));
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
