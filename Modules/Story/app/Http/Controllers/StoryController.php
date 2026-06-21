<?php

namespace Modules\Story\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Notifications\Services\NotificationService;
use Modules\Story\Http\Requests\StoryStoreRequest;
use Modules\Story\Http\Requests\StoryUpdateRequest;
use Modules\Story\Models\Story;

class StoryController extends Controller
{
    public function index()
    {
        $stories = Story::latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'لیست تمام استوری‌ها',
            'data' => $stories
        ]);
    }

    /**
     * نمایش لیست استوری‌های منتشر شده (فرانت)
     */
    public function published()
    {
        $stories = Story::latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'لیست استوری‌های منتشر شده',
            'data' => $stories
        ]);
    }

    /**
     * ایجاد استوری جدید
     */
    public function store(StoryStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        // ذخیره فایل کاور
        if ($request->hasFile('cover')) {
            $path = $request->file('cover')->store('stories/covers', 'public');
            $data['cover'] = $path;
        }
        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('stories/videos', 'public');
            $data['video'] = $videoPath;
        }

        // تنظیم مقدار پیش‌فرض
        $data['seen_count'] = 0;


        $story = Story::create($data);
        $notifications->create(
            " ثبت  استوری",
            " استوری  {$story->title}در سیستم ثبت  شد",
            "notification_content",
            ['story' => $story->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'استوری با موفقیت ایجاد شد',
            'data' => $story
        ], 201);
    }

    /**
     * نمایش جزئیات یک استوری (ادمین)
     */
    public function show(Story $story)
    {
        return response()->json([
            'success' => true,
            'data' => $story
        ]);
    }

    /**
     * نمایش جزئیات یک استوری در فرانت (با افزایش بازدید)
     */


    /**
     * بروزرسانی استوری
     */
    public function update(StoryUpdateRequest $request, Story $story, NotificationService $notifications)
    {
        $data = $request->validated();

        // ذخیره فایل کاور جدید
        if ($request->hasFile('cover')) {
            // حذف فایل قبلی
            if ($story->cover) {
                Storage::disk('public')->delete($story->cover);
            }

            $path = $request->file('cover')->store('stories/covers', 'public');
            $data['cover'] = $path;
        }
        if ($request->hasFile('video')) {
            // حذف فایل قبلی
            if ($story->video) {
                Storage::disk('public')->delete($story->video);
            }

            $pathVideo = $request->file('video')->store('stories/videos', 'public');
            $data['video'] = $pathVideo;
        }

        $story->update($data);
        $notifications->create(
            " ویرایش  استوری",
            " استوری  {$story->title}در سیستم ویرایش  شد",
            "notification_content",
            ['story' => $story->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'استوری با موفقیت بروزرسانی شد',
            'data' => $story
        ]);
    }

    /**
     * حذف استوری
     */
    public function destroy(Story $story)
    {
        // حذف فایل کاور
        if ($story->cover) {
            Storage::disk('public')->delete($story->cover);
        }
        if ($story->video) {
            Storage::disk('public')->delete($story->video);
        }
        $story->delete();

        return response()->json([
            'success' => true,
            'message' => 'استوری با موفقیت حذف شد'
        ]);
    }



    /**
     * افزایش بازدید استوری (API)
     */
    public function incrementView(Story $story)
    {
        // فقط استوری منتشر شده قابل افزایش بازدید است
        if ($story->status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'این استوری منتشر نشده است'
            ], 403);
        }

        $story->incrementSeenCount();
        $story->refresh();

        return response()->json([
            'success' => true,
            'message' => 'بازدید با موفقیت ثبت شد',
            'data' => [
                'id' => $story->id,
                'seen_count' => $story->seen_count
            ]
        ]);
    }

    /**
     * دریافت استوری‌های پربازدید
     */


    /**
     * دریافت استوری‌های جدید
     */
    public function latest()
    {
        $stories = Story::active()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'آخرین استوری‌ها',
            'data' => $stories
        ]);
    }
}
